<?php

namespace App\Http\Controllers;

use App\Jobs\FulfillPendingOrdersJob;
use App\Models\NetworkService;
use App\Models\ResultCheckerPin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminResultCheckerPinsController extends Controller
{
    public function index(Request $request)
    {
        $services = NetworkService::resultsChecker()
            ->orderBy('name')
            ->get();

        $pinsQuery = ResultCheckerPin::query()
            ->with(['service', 'order'])
            ->latest();

        $serviceId = $request->input('service_id');
        if ($serviceId) {
            $pinsQuery->where('service_id', $serviceId);
        }

        $status = $request->input('status');
        if ($status) {
            $pinsQuery->where('status', $status);
        }

        $pins = $pinsQuery->paginate(50)->withQueryString();

        $pricingTiers = [];
        $service = null;
        if ($serviceId) {
            $service = $services->find($serviceId);
            if ($service) {
                $pricingTiers = $service->pricingTiers()->orderBy('min_quantity')->get();
            }
        }

        return view('admin.result_checkers.pins', [
            'pins' => $pins,
            'services' => $services,
            'selectedService' => $service,
            'selectedStatus' => $status,
            'pricingTiers' => $pricingTiers,
        ]);
    }

    public function updateBasePrice(Request $request)
    {
        $request->validate([
            'network_service_id' => 'required|exists:network_services,id',
            'base_price' => 'required|numeric|min:0',
        ]);

        $service = NetworkService::find($request->input('network_service_id'));
        $service->base_price = $request->input('base_price');
        $service->save();

        return back()->with('success', 'Base price updated successfully.');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'service_id' => ['required', 'exists:network_services,id'],
            'pins_file' => ['nullable', 'file', 'mimes:csv,txt', 'max:5120'],
            'pins_text' => ['nullable', 'string'],
        ]);

        $service = NetworkService::resultsChecker()->find($validated['service_id']);
        if (! $service) {
            return back()->withErrors(['service_id' => 'Selected service is not a result checker.'])->withInput();
        }

        $content = '';
        if ($request->hasFile('pins_file')) {
            $content = (string) file_get_contents($request->file('pins_file')->getRealPath());
        } else {
            $content = (string) $request->input('pins_text', '');
        }

        if (trim($content) === '') {
            return back()->withErrors(['pins_text' => 'Provide a CSV file or paste pin data.'])->withInput();
        }

        [$rows, $errorMessage] = $this->parsePins($content);
        if ($errorMessage !== null) {
            return back()->withErrors(['pins_text' => $errorMessage])->withInput();
        }

        if (empty($rows)) {
            return back()->withErrors(['pins_text' => 'No valid PIN rows were found.'])->withInput();
        }

        $pins = collect($rows);
        $pinsList = $pins->pluck('pin')->unique()->values();
        $serialsList = $pins->pluck('serial')->unique()->values();

        $existingPins = ResultCheckerPin::query()
            ->where('service_id', $validated['service_id'])
            ->whereIn('pin', $pinsList)
            ->count();

        $existingSerials = ResultCheckerPin::query()
            ->where('service_id', $validated['service_id'])
            ->whereIn('serial', $serialsList)
            ->count();

        if ($existingPins > 0 || $existingSerials > 0) {
            return back()->withErrors([
                'pins_text' => 'Some PINs or serials already exist for this service. Remove duplicates and try again.',
            ])->withInput();
        }

        $now = now();
        DB::transaction(function () use ($validated, $rows, $now) {
            $payload = array_map(function ($row) use ($validated, $now) {
                return [
                    'service_id' => $validated['service_id'],
                    'pin' => $row['pin'],
                    'serial' => $row['serial'],
                    'status' => 'available',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }, $rows);

            ResultCheckerPin::insert($payload);
        });

        dispatch(new FulfillPendingOrdersJob($validated['service_id']));

        return redirect()
            ->route('admin.result-checkers.pins.index')
            ->with('success', count($rows) . ' PINs uploaded successfully.');
    }

    private function parsePins(string $content): array
    {
        $lines = preg_split('/\r\n|\n|\r/', trim($content));
        if (! $lines) {
            return [[], 'No valid rows provided.'];
        }

        $rows = [];
        $seenPairs = [];
        $seenPins = [];
        $seenSerials = [];

        foreach ($lines as $index => $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $columns = str_getcsv($line);
            if ($index === 0 && count($columns) >= 2) {
                $header = strtolower(trim($columns[0])) . ',' . strtolower(trim($columns[1]));
                if (str_contains($header, 'serial') && str_contains($header, 'pin')) {
                    continue;
                }
            }

            if (count($columns) < 2) {
                return [[], 'Each row must contain serial and pin values.'];
            }

            $serial = trim((string) $columns[0]);
            $pin = trim((string) $columns[1]);

            if ($serial === '' || $pin === '') {
                return [[], 'Serial and pin values cannot be empty.'];
            }

            $pinKey = strtolower($pin);
            $serialKey = strtolower($serial);
            $pairKey = $serialKey . '|' . $pinKey;

            if (isset($seenPairs[$pairKey]) || isset($seenPins[$pinKey]) || isset($seenSerials[$serialKey])) {
                return [[], 'Duplicate serials or PINs detected. Remove duplicates and try again.'];
            }

            $seenPairs[$pairKey] = true;
            $seenPins[$pinKey] = true;
            $seenSerials[$serialKey] = true;
            $rows[] = [
                'serial' => $serial,
                'pin' => $pin,
            ];
        }

        return [$rows, null];
    }
}
