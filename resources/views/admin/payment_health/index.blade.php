@extends('layouts.admin')

@section('content')
<x-admin-layout title="Payment Health" subtitle="Read-only operational view of payment reconciliation, provider health, and fulfillment backlog" active="payment-health">
    <div class="space-y-8">

        @if (session('success'))
            <div class="rounded-md bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-800">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="rounded-md bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-800">{{ session('error') }}</div>
        @endif

        {{-- Alerts --}}
        @if (count($alerts))
            <div class="space-y-2">
                @foreach ($alerts as $alert)
                    <div class="rounded-md px-4 py-3 text-sm border {{ $alert['level'] === 'critical' ? 'bg-red-50 border-red-200 text-red-800' : 'bg-amber-50 border-amber-200 text-amber-800' }}">
                        <span class="font-semibold uppercase text-xs tracking-wide">{{ $alert['level'] }}</span>
                        — {{ $alert['message'] }}
                    </div>
                @endforeach
            </div>
        @else
            <div class="rounded-md bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-800">No active alerts.</div>
        @endif

        {{-- Scheduler health --}}
        <div class="bg-white shadow-sm rounded-lg p-5">
            <h3 class="text-sm font-semibold text-gray-700 mb-3">Reconciliation Scheduler</h3>
            @php($sh = $schedulerHealth)
            <div class="flex flex-wrap items-center gap-4 text-sm">
                <span class="px-2 py-1 rounded text-xs font-medium
                    {{ $sh['status'] === 'ok' ? 'bg-green-100 text-green-800' : ($sh['status'] === 'warning' ? 'bg-amber-100 text-amber-800' : 'bg-red-100 text-red-800') }}">
                    {{ strtoupper($sh['status']) }}
                </span>
                @if ($sh['known'])
                    <span>Last run finished: {{ \Illuminate\Support\Carbon::parse($sh['finished_at'])->diffForHumans() }}</span>
                    <span>Duration: {{ $sh['duration_seconds'] ?? '—' }}s</span>
                    <span>Records examined: {{ $sh['records_examined'] ?? '—' }}</span>
                    <span>Last outcome: {{ $sh['status'] === 'ok' ? 'success' : ($sh['status'] ?? '—') }}</span>
                @else
                    <span>{{ $sh['message'] }}</span>
                @endif
            </div>
            @if ($sh['known'] && ! empty($sh['totals']))
                <div class="mt-3 text-xs text-gray-500 overflow-x-auto">
                    <table class="min-w-full">
                        <thead><tr class="text-left"><th class="pr-4 py-1">Type</th><th class="pr-4">Completed</th><th class="pr-4">Cancelled</th><th class="pr-4">Left Pending</th><th class="pr-4">No Gateway</th><th class="pr-4">Integrity Mismatch</th><th class="pr-4">Manual Review</th><th class="pr-4">Exceptions</th></tr></thead>
                        <tbody>
                        @foreach ($sh['totals'] as $type => $stats)
                            <tr class="border-t border-gray-100">
                                <td class="pr-4 py-1">{{ $labels[$type] ?? $type }}</td>
                                <td class="pr-4">{{ $stats['completed'] ?? 0 }}</td>
                                <td class="pr-4">{{ $stats['cancelled'] ?? 0 }}</td>
                                <td class="pr-4">{{ $stats['left_pending'] ?? 0 }}</td>
                                <td class="pr-4">{{ $stats['no_gateway'] ?? 0 }}</td>
                                <td class="pr-4">{{ $stats['integrity_mismatch'] ?? 0 }}</td>
                                <td class="pr-4">{{ $stats['manual_review'] ?? 0 }}</td>
                                <td class="pr-4">{{ $stats['exception'] ?? 0 }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        {{-- Health summary per payable type --}}
        <div class="bg-white shadow-sm rounded-lg p-5 overflow-x-auto">
            <h3 class="text-sm font-semibold text-gray-700 mb-3">Payment Health Summary</h3>
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase text-gray-500 border-b border-gray-200">
                        <th class="py-2 pr-4">Type</th>
                        <th class="py-2 pr-4">Pending &lt;30m</th>
                        <th class="py-2 pr-4">Pending 30m–24h</th>
                        <th class="py-2 pr-4">Pending &gt;24h</th>
                        <th class="py-2 pr-4">Reconciliation Due</th>
                        <th class="py-2 pr-4">Repeated UNKNOWN</th>
                        <th class="py-2 pr-4">Manual Review</th>
                        <th class="py-2 pr-4">Missing Gateway</th>
                        <th class="py-2 pr-4">Integrity Mismatch</th>
                        <th class="py-2 pr-4">Confirmed Failed</th>
                        <th class="py-2 pr-4">Reconciled OK</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($summary['per_type'] as $type => $b)
                        <tr class="border-b border-gray-100">
                            <td class="py-2 pr-4 font-medium">{{ $labels[$type] ?? $type }}</td>
                            <td class="py-2 pr-4">{{ $b['pending_under_30m'] }}</td>
                            <td class="py-2 pr-4">{{ $b['pending_30m_to_24h'] }}</td>
                            <td class="py-2 pr-4 {{ $b['pending_over_24h'] > 0 ? 'text-red-600 font-semibold' : '' }}">{{ $b['pending_over_24h'] }}</td>
                            <td class="py-2 pr-4">{{ $b['reconciliation_due'] }}</td>
                            <td class="py-2 pr-4">{{ $b['repeated_unknown'] }}</td>
                            <td class="py-2 pr-4 {{ $b['manual_review_required'] > 0 ? 'text-amber-700 font-semibold' : '' }}">{{ $b['manual_review_required'] }}</td>
                            <td class="py-2 pr-4">{{ $b['missing_gateway'] }}</td>
                            <td class="py-2 pr-4 {{ $b['integrity_mismatch'] > 0 ? 'text-red-600 font-semibold' : '' }}">{{ $b['integrity_mismatch'] }}</td>
                            <td class="py-2 pr-4">{{ $b['confirmed_failed'] }}</td>
                            <td class="py-2 pr-4">{{ $b['reconciled_successfully'] }}</td>
                        </tr>
                    @endforeach
                    <tr class="font-semibold bg-gray-50">
                        <td class="py-2 pr-4">Total</td>
                        @foreach (array_keys($summary['totals']) as $key)
                            <td class="py-2 pr-4">{{ $summary['totals'][$key] }}</td>
                        @endforeach
                    </tr>
                </tbody>
            </table>
        </div>

        {{-- Provider health --}}
        <div class="bg-white shadow-sm rounded-lg p-5 overflow-x-auto">
            <h3 class="text-sm font-semibold text-gray-700 mb-3">Provider (Gateway) Health</h3>
            @if (count($providerHealth))
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase text-gray-500 border-b border-gray-200">
                            <th class="py-2 pr-4">Gateway</th>
                            <th class="py-2 pr-4">Pending</th>
                            <th class="py-2 pr-4">Repeated UNKNOWN</th>
                            <th class="py-2 pr-4">Reconciled Success</th>
                            <th class="py-2 pr-4">Failed</th>
                            <th class="py-2 pr-4">Manual Review</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($providerHealth as $gateway => $g)
                            <tr class="border-b border-gray-100">
                                <td class="py-2 pr-4 font-medium">{{ $gateway }}</td>
                                <td class="py-2 pr-4">{{ $g['pending_count'] }}</td>
                                <td class="py-2 pr-4">{{ $g['unknown_count'] }}</td>
                                <td class="py-2 pr-4">{{ $g['reconciled_success_count'] }}</td>
                                <td class="py-2 pr-4">{{ $g['failed_count'] }}</td>
                                <td class="py-2 pr-4">{{ $g['manual_review_count'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <p class="text-sm text-gray-500">No gateway-attributed payment records yet.</p>
            @endif
        </div>

        {{-- Queue / fulfillment visibility --}}
        <div class="bg-white shadow-sm rounded-lg p-5">
            <h3 class="text-sm font-semibold text-gray-700 mb-3">Queue &amp; Fulfillment</h3>
            <div class="flex flex-wrap gap-6 text-sm">
                <div><span class="text-gray-500">Failed jobs:</span> <span class="font-semibold {{ $queueHealth['failed_jobs_total'] > 0 ? 'text-red-600' : '' }}">{{ $queueHealth['failed_jobs_total'] }}</span></div>
                <div><span class="text-gray-500">Queued jobs:</span> <span class="font-semibold">{{ $queueHealth['queued_jobs_total'] }}</span></div>
                <div><span class="text-gray-500">Orders awaiting fulfillment:</span> <span class="font-semibold {{ $queueHealth['orders_awaiting_fulfillment'] > 0 ? 'text-amber-700' : '' }}">{{ $queueHealth['orders_awaiting_fulfillment'] }}</span></div>
                <div><span class="text-gray-500">Fulfillment failed:</span> <span class="font-semibold {{ $queueHealth['orders_fulfillment_failed'] > 0 ? 'text-red-600' : '' }}">{{ $queueHealth['orders_fulfillment_failed'] }}</span></div>
            </div>
            @if (count($queueHealth['failed_jobs_recent']))
                <div class="mt-3 overflow-x-auto">
                    <table class="min-w-full text-xs">
                        <thead><tr class="text-left text-gray-500 border-b border-gray-200"><th class="py-1 pr-4">Queue</th><th class="py-1 pr-4">Job</th><th class="py-1 pr-4">Exception (first line)</th><th class="py-1 pr-4">Failed At</th></tr></thead>
                        <tbody>
                        @foreach ($queueHealth['failed_jobs_recent'] as $job)
                            <tr class="border-b border-gray-100">
                                <td class="py-1 pr-4">{{ $job['queue'] }}</td>
                                <td class="py-1 pr-4">{{ $job['job'] }}</td>
                                <td class="py-1 pr-4 text-gray-600">{{ \Illuminate\Support\Str::limit($job['exception_summary'], 120) }}</td>
                                <td class="py-1 pr-4">{{ $job['failed_at'] }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        {{-- Manual review queue --}}
        <div class="space-y-4">
            <h3 class="text-sm font-semibold text-gray-700">Manual Review Queue</h3>

            <form method="GET" action="{{ route('admin.payment-health.index') }}" class="flex flex-wrap gap-3 items-end bg-white shadow-sm rounded-lg p-4">
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Type</label>
                    <select name="type" class="rounded-md border-gray-300 text-sm">
                        <option value="">All</option>
                        @foreach ($labels as $key => $label)
                            <option value="{{ $key }}" @selected(($filters['type'] ?? null) === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">State</label>
                    <select name="state" class="rounded-md border-gray-300 text-sm">
                        <option value="">Needs attention (default)</option>
                        <option value="pending" @selected(($filters['state'] ?? null) === 'pending')>Pending</option>
                        <option value="failed" @selected(($filters['state'] ?? null) === 'failed')>Failed</option>
                        <option value="manual_review" @selected(($filters['state'] ?? null) === 'manual_review')>Manual Review</option>
                        <option value="missing_gateway" @selected(($filters['state'] ?? null) === 'missing_gateway')>Missing Gateway</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Age</label>
                    <select name="age" class="rounded-md border-gray-300 text-sm">
                        <option value="">Any</option>
                        <option value="under_30m" @selected(($filters['age'] ?? null) === 'under_30m')>&lt; 30 min</option>
                        <option value="30m_to_24h" @selected(($filters['age'] ?? null) === '30m_to_24h')>30 min – 24h</option>
                        <option value="over_24h" @selected(($filters['age'] ?? null) === 'over_24h')>&gt; 24h</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Gateway</label>
                    <input type="text" name="gateway" value="{{ $filters['gateway'] ?? '' }}" class="rounded-md border-gray-300 text-sm" placeholder="e.g. paystack">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Reference</label>
                    <input type="text" name="reference" value="{{ $filters['reference'] ?? '' }}" class="rounded-md border-gray-300 text-sm" placeholder="Search reference">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Reconciliation note contains</label>
                    <input type="text" name="reason" value="{{ $filters['reason'] ?? '' }}" class="rounded-md border-gray-300 text-sm" placeholder="e.g. integrity_mismatch">
                </div>
                <div>
                    <button type="submit" class="px-4 py-2 bg-gray-800 text-white text-sm rounded-md">Filter</button>
                </div>
                @if (array_filter($filters))
                    <div>
                        <a href="{{ route('admin.payment-health.index') }}" class="text-sm text-gray-500 underline">Clear</a>
                    </div>
                @endif
            </form>

            <x-table :headers="['Type', 'ID', 'Reference', 'Gateway', 'Amount', 'State', 'Created', 'Attempts', 'Last Reconciled', 'Next Reconciliation', 'Note', '']">
                @forelse ($queue as $row)
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 text-sm">{{ $labels[$row->payable_type] ?? $row->payable_type }}</td>
                        <td class="px-6 py-4 text-sm">#{{ $row->id }}</td>
                        <td class="px-6 py-4 text-sm font-mono text-xs">{{ $row->reference ?? '—' }}</td>
                        <td class="px-6 py-4 text-sm">
                            @if ($row->gateway)
                                {{ $row->gateway }}
                            @else
                                <span class="text-amber-700 font-medium">Manual Review / Original Gateway Unknown</span>
                            @endif
                        </td>
                        <td class="px-6 py-4 text-sm">{{ $row->amount !== null ? number_format((float) $row->amount, 2) : '—' }}</td>
                        <td class="px-6 py-4 text-sm">{{ $row->payment_state }}</td>
                        <td class="px-6 py-4 text-sm">{{ \Illuminate\Support\Carbon::parse($row->created_at)->diffForHumans() }}</td>
                        <td class="px-6 py-4 text-sm">{{ $row->reconciliation_attempts }}</td>
                        <td class="px-6 py-4 text-sm">{{ $row->last_reconciliation_at ? \Illuminate\Support\Carbon::parse($row->last_reconciliation_at)->diffForHumans() : '—' }}</td>
                        <td class="px-6 py-4 text-sm">{{ $row->next_reconciliation_at ? \Illuminate\Support\Carbon::parse($row->next_reconciliation_at)->diffForHumans() : '—' }}</td>
                        <td class="px-6 py-4 text-sm max-w-xs truncate" title="{{ $row->reconciliation_note }}">{{ $row->reconciliation_note ?? '—' }}</td>
                        <td class="px-6 py-4 text-sm">
                            @if ($row->gateway)
                                <form method="POST" action="{{ route('admin.payment-health.recheck') }}" onsubmit="return confirm('Re-verify this payment against its own stored gateway? This will not create a new charge.');">
                                    @csrf
                                    <input type="hidden" name="payable_type" value="{{ $row->payable_type }}">
                                    <input type="hidden" name="payable_id" value="{{ $row->id }}">
                                    <button type="submit" class="px-3 py-1 text-xs bg-blue-600 text-white rounded-md">Recheck Payment</button>
                                </form>
                            @else
                                <span class="text-xs text-gray-400">No gateway on record</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="12" class="px-6 py-4 text-center text-sm text-gray-500">Nothing needs attention right now.</td></tr>
                @endforelse
            </x-table>

            @if ($queue->hasPages())
                <div class="flex justify-end pt-2">{{ $queue->links() }}</div>
            @endif
        </div>
    </div>
</x-admin-layout>
@endsection
