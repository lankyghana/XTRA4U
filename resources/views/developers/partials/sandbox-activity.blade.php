{{--
    Simulated activity only. These numbers come from the developer_sandbox_* tables and are never part of
    sales, earnings, commissions, wallet balances or production order counts.
--}}
<x-developers.section tone="sandbox" :flush="true" data-section="sandbox-activity">
    <div class="px-4 sm:px-5 py-3 border-b border-amber-200 bg-amber-50 flex items-center justify-between gap-2 flex-wrap">
        <h2 class="font-semibold text-gray-900 flex items-center gap-2 flex-wrap">Sandbox activity <x-badge size="sm" variant="pending">simulated &middot; test keys</x-badge></h2>
        <span class="text-xs text-gray-600">No real payments, deliveries, messages or earnings.</span>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 p-4 sm:p-5">
        @foreach ([['Sandbox orders', 'sandbox-orders', $sandbox['orders']], ['Simulated payments', 'sandbox-payments', $sandbox['payments']], ['Completed', 'sandbox-completed', $sandbox['completed']], ['Failed', 'sandbox-failed', $sandbox['failed']]] as [$label, $stat, $n])
            <div class="rounded-lg border border-gray-200 px-4 py-3">
                <div class="text-xs text-gray-500 uppercase tracking-wide">{{ $label }}</div>
                <div class="text-2xl font-semibold text-gray-900 mt-1" data-stat="{{ $stat }}">{{ number_format($n) }}</div>
            </div>
        @endforeach
    </div>

    <div class="grid md:grid-cols-2 gap-4 px-4 sm:px-5 pb-4">
        <div class="rounded-lg border border-gray-200 overflow-hidden">
            <h3 class="text-sm font-medium text-gray-900 px-4 py-2.5 border-b border-gray-100 bg-gray-50">Recent sandbox orders</h3>
            @forelse($sandbox['recent_orders'] as $o)
                <div class="px-4 py-2 text-xs flex justify-between gap-2">
                    <span class="break-all"><code>{{ $o->order_ref }}</code></span>
                    <span class="whitespace-nowrap text-gray-600">{{ $o->status }} &middot; {{ $o->created_at->diffForHumans() }}</span>
                </div>
            @empty
                <p class="px-4 py-4 text-xs text-gray-500">No sandbox orders yet. Use a test key (<code>xt_test_…</code>).</p>
            @endforelse
        </div>
        <div class="rounded-lg border border-gray-200 overflow-hidden">
            <h3 class="text-sm font-medium text-gray-900 px-4 py-2.5 border-b border-gray-100 bg-gray-50">Recent sandbox failures</h3>
            @forelse($sandbox['recent_failures'] as $o)
                <div class="px-4 py-2 text-xs flex justify-between gap-2">
                    <span class="break-all"><code>{{ $o->order_ref }}</code></span>
                    <span class="whitespace-nowrap text-gray-600">{{ $o->payment_status === 'failed' ? 'payment failed' : 'fulfillment failed' }} &middot; {{ $o->updated_at->diffForHumans() }}</span>
                </div>
            @empty
                <p class="px-4 py-4 text-xs text-gray-500">No simulated failures.</p>
            @endforelse
        </div>
    </div>

    <div class="px-4 sm:px-5 pb-5">
        <div class="rounded-lg border border-gray-200 overflow-hidden">
            <h3 class="text-sm font-medium text-gray-900 px-4 py-2.5 border-b border-gray-100 bg-gray-50">
                Sandbox webhook deliveries
                <span class="block sm:inline font-normal text-xs text-gray-500">
                    &middot; {{ $sandbox['webhooks']['endpoints'] }} sandbox endpoint(s) &middot; last 7 days:
                    <b>{{ $sandbox['webhooks']['deliveries_7d']['succeeded'] }}</b> delivered,
                    <b>{{ $sandbox['webhooks']['deliveries_7d']['pending'] }}</b> pending,
                    <b>{{ $sandbox['webhooks']['deliveries_7d']['failed'] }}</b> failed
                </span>
            </h3>
            @forelse($sandbox['webhooks']['recent'] as $del)
                <div class="px-4 py-2 text-xs flex justify-between gap-2">
                    <span class="break-all">{{ $del->event?->type }} &rarr; {{ $del->webhook?->displayHost() }}</span>
                    <span class="text-gray-600">{{ $del->status }}@if($del->last_status_code) ({{ $del->last_status_code }})@endif</span>
                </div>
            @empty
                <p class="px-4 py-4 text-xs text-gray-500">No sandbox webhook deliveries yet. Add a sandbox endpoint under Webhooks.</p>
            @endforelse
        </div>
    </div>
</x-developers.section>
