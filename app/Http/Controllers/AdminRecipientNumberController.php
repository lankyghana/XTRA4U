<?php

namespace App\Http\Controllers;

use App\Models\RecipientNumberLog;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminRecipientNumberController extends Controller
{
    public function index(Request $request)
    {
        $query = $this->baseQuery($request);

        $logs = $query
            ->orderByDesc('used_at')
            ->orderByDesc('id')
            ->paginate(50)
            ->appends($request->query());

        return view('admin.recipient_numbers.index', [
            'logs' => $logs,
            'filters' => [
                'date_from'    => $request->query('date_from'),
                'date_to'      => $request->query('date_to'),
                'service_type' => $request->query('service_type'),
                'vendor_id'    => $request->query('vendor_id'),
                'phone'        => $request->query('phone'),
                'distinct'     => $request->query('distinct'),
            ],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $format   = $request->query('format', 'csv');
        $distinct = (bool) $request->query('distinct', false);

        if ($distinct) {
            $query = $this->distinctPhoneQuery($request);

            return response()->streamDownload(function () use ($query, $format) {
                $out = $format === 'csv' ? fopen('php://output', 'w') : null;
                if ($out) {
                    fputcsv($out, ['phone_number']);
                }

                $query->chunk(5000, function ($rows) use ($format, $out) {
                    foreach ($rows as $row) {
                        if ($format === 'csv') {
                            fputcsv($out, [$row->phone_number]);
                        } else {
                            echo $row->phone_number . "\n";
                        }
                    }
                });

                if ($out) {
                    fclose($out);
                }
            }, "recipient-numbers-distinct.{$format}", [
                'Content-Type' => $format === 'csv' ? 'text/csv; charset=UTF-8' : 'text/plain; charset=UTF-8',
            ]);
        }

        $query = $this->baseQuery($request)->orderBy('id');

        if ($format === 'txt') {
            return response()->streamDownload(function () use ($query) {
                $query->chunkById(5000, function ($rows) {
                    foreach ($rows as $row) {
                        echo $row->phone_number . "\n";
                    }
                });
            }, 'recipient-numbers.txt', [
                'Content-Type' => 'text/plain; charset=UTF-8',
            ]);
        }

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['phone_number', 'service_type', 'vendor_id', 'order_id', 'used_at']);

            $query->chunkById(5000, function ($rows) use ($out) {
                foreach ($rows as $row) {
                    fputcsv($out, [
                        $row->phone_number,
                        $row->service_type,
                        $row->vendor_id,
                        $row->order_id,
                        optional($row->used_at)->toDateTimeString(),
                    ]);
                }
            });

            fclose($out);
        }, 'recipient-numbers.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function copy(Request $request): StreamedResponse
    {
        $distinct = (bool) $request->query('distinct', false);

        if ($distinct) {
            $query = $this->distinctPhoneQuery($request);

            return response()->stream(function () use ($query) {
                $query->chunk(5000, function ($rows) {
                    foreach ($rows as $row) {
                        echo $row->phone_number . "\n";
                    }
                });
            }, 200, [
                'Content-Type' => 'text/plain; charset=UTF-8',
            ]);
        }

        $query = $this->baseQuery($request)->orderBy('id');

        return response()->stream(function () use ($query) {
            $query->chunkById(5000, function ($rows) {
                foreach ($rows as $row) {
                    echo $row->phone_number . "\n";
                }
            });
        }, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }

    private function baseQuery(Request $request)
    {
        $query = RecipientNumberLog::query()
            ->select(['id', 'phone_number', 'vendor_id', 'order_id', 'service_type', 'used_at'])
            ->with(['vendor:id,name,vendor_code']);

        return $this->applyFilters($query, $request);
    }

    private function distinctPhoneQuery(Request $request)
    {
        return $this->applyFilters(
            RecipientNumberLog::query()->select('phone_number')->distinct(),
            $request
        )->orderBy('phone_number');
    }

    private function applyFilters($query, Request $request)
    {
        if ($request->filled('phone')) {
            $query->where('phone_number', 'like', '%' . $request->query('phone') . '%');
        }

        if ($request->filled('service_type')) {
            $query->where('service_type', 'like', '%' . $request->query('service_type') . '%');
        }

        if ($request->filled('vendor_id')) {
            $query->where('vendor_id', (int) $request->query('vendor_id'));
        }

        if ($request->filled('date_from')) {
            try {
                $from = CarbonImmutable::parse($request->query('date_from'))->startOfDay();
                $query->where('used_at', '>=', $from);
            } catch (\Throwable $e) {
                // Ignore invalid dates
            }
        }

        if ($request->filled('date_to')) {
            try {
                $to = CarbonImmutable::parse($request->query('date_to'))->endOfDay();
                $query->where('used_at', '<=', $to);
            } catch (\Throwable $e) {
                // Ignore invalid dates
            }
        }

        return $query;
    }
}
