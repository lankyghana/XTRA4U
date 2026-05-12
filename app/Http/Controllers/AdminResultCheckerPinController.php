<?php

namespace App\Http\Controllers;

use App\Models\NetworkService;
use App\Models\ResultCheckerPin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AdminResultCheckerPinController extends Controller
{
    public function index(Request $request)
    {
        $services = NetworkService::resultsChecker()->orderBy('name')->get();
        $selectedService = $request->input('service_id');
        $selectedStatus = $request->input('status');

        $pinsQuery = ResultCheckerPin::with('service', 'order')
            ->latest();

        if ($selectedService) {
            $pinsQuery->where('service_id', $selectedService);
        }

        if ($selectedStatus) {
            $pinsQuery->where('status', $selectedStatus);
        }

        $pins = $pinsQuery->paginate(50);
        
        $pricingTiers = [];
        $service = null;
        if ($selectedService) {
            $service = $services->find($selectedService);
            if ($service) {
                $pricingTiers = $service->pricingTiers()->orderBy('min_quantity')->get();
            }
        }


        return view('admin.result_checkers.pins', [
            'services' => $services,
            'pins' => $pins,
            'selectedService' => $service,
            'selectedStatus' => $selectedStatus,
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
        $request->validate([
            'service_id' => 'required|exists:network_services,id',
            'pins_file' => 'nullable|file|mimes:csv,txt',
            'pins_text' => 'nullable|string',
        ]);

        if (!$request->hasFile('pins_file') && empty($request->input('pins_text'))) {
            return back()->withErrors(['pins_file' => 'Please upload a file or paste PINs.']);
        }

        $serviceId = $request->input('service_id');
        $pins = [];

        if ($request->hasFile('pins_file')) {
            $path = $request->file('pins_file')->getRealPath();
            $file = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $pins = array_merge($pins, $file);
        }

        if (!empty($request->input('pins_text'))) {
            $text = $request->input('pins_text');
            $lines = explode("\n", str_replace("\r", '', $text));
            $pins = array_merge($pins, $lines);
        }

        $count = 0;
        DB::beginTransaction();
        try {
            foreach ($pins as $line) {
                $line = trim($line);
                if (empty($line)) continue;

                $parts = str_getcsv($line);
                if (count($parts) < 2) continue;

                $serial = trim($parts[0]);
                $pin = trim($parts[1]);

                if (empty($serial) || empty($pin)) continue;

                ResultCheckerPin::firstOrCreate(
                    [
                        'service_id' => $serviceId,
                        'serial' => $serial,
                        'pin' => $pin,
                    ],
                    [
                        'status' => 'available',
                    ]
                );
                $count++;
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to bulk upload pins: ' . $e->getMessage());
            return back()->withErrors(['pins_file' => 'An error occurred during the bulk upload. Please check your file and try again.']);
        }

        return redirect()->route('admin.result-checkers.pins.index', ['service_id' => $serviceId])
            ->with('success', "Successfully uploaded {$count} PINs.");
    }
}
