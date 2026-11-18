{{--
    Simulated activity only. These numbers come from the developer_sandbox_* tables and are never part of
    sales, earnings, commissions, wallet balances or production order counts.
--}}
<section class="bg-white rounded-xl border border-amber-200 mb-8" data-section="sandbox-activity">
    <div class="px-4 py-3 border-b border-amber-200 bg-amber-50 rounded-t-xl flex items-center justify-between gap-2 flex-wrap">
        <h2 class="font-semibold">Sandbox activity <span class="ml-1 rounded-full bg-amber-100 text-amber-800 text-xs px-2 py-0.5 font-normal">simulated &middot; test keys</span></h2>
        <span class="text-xs text-gray-600">No real payments, deliveries, messages or earnings.</span>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 p-4">
        <div><div class="text-xs text-gray-500">Sandbox orders</div><div class="text-xl font-semibold" data-stat="sandbox-orders">{{ number_format($sandbox['orders']) }}</div></div>
        <div><div class="text-xs text-gray-500">Simulated payments</div><div class="text-xl font-semibold" data-stat="sandbox-payments">{{ number_format($sandbox['payments']) }}</div></div>
        <div><div class="text-xs text-gray-500">Completed</div><div class="text-xl font-semibold" data-stat="sandbox-completed">{{ number_format($sandbox['completed']) }}</div></div>
        <div><div class="text-xs text-gray-500">Failed</div><div class="text-xl font-semibold" data-stat="sandbox-failed">{{ number_format($sandbox['failed']) }}</div></div>
    </div>

    <div class="grid md:grid-cols-2 gap-4 px-4 pb-4">
        <div class="rounded-lg border border-gray-200">
            <h3 class="text-sm font-medium px-3 py-2 border-b border-gray-100">Recent sandbox orders</h3>
            @forelse($sandbox['recent_orders'] as $o)
                <div class="px-3 py-2 text-xs border-b border-gray-100 flex justify-between gap-2">
                    <span class="break-all"><code>{{ $o->order_ref }}</code></span>
                    <span class="whitespace-nowrap">{{ $o->status }} &middot; {{ $o->created_at->diffForHumans() }}</span>
                </div>
            @empty
                <p class="px-3 py-3 text-xs text-gray-500">No sandbox orders yet. Use a test key (<code>xt_test_…</code>).</p>
            @endforelse
        </div>
        <div class="rounded-lg border border-gray-200">
            <h3 class="text-sm font-medium px-3 py-2 border-b border-gray-100">Recent sandbox failures</h3>
            @forelse($sandbox['recent_failures'] as $o)
                <div class="px-3 py-2 text-xs border-b border-gray-100 flex justify-between gap-2">
                    <span class="break-all"><code>{{ $o->order_ref }}</code></span>
                    <span class="whitespace-nowrap">{{ $o->payment_status === 'failed' ? 'payment failed' : 'fulfillment failed' }} &middot; {{ $o->updated_at->diffForHumans() }}</span>
                </div>
            @empty
                <p class="px-3 py-3 text-xs text-gray-500">No simulated failures.</p>
            @endforelse
        </div>
    </div>

    <div class="px-4 pb-4">
        <div class="rounded-lg border border-gray-200">
            <h3 class="text-sm font-medium px-3 py-2 border-b border-gray-100">
                Sandbox webhook deliveries
                <span class="font-normal text-xs text-gray-500">
                    &middot; {{ $sandbox['webhooks']['endpoints'] }} sandbox endpoint(s) &middot; last 7 days:
                    <b>{{ $sandbox['webhooks']['deliveries_7d']['succeeded'] }}</b> delivered,
                    <b>{{ $sandbox['webhooks']['deliveries_7d']['pending'] }}</b> pending,
                    <b>{{ $sandbox['webhooks']['deliveries_7d']['failed'] }}</b> failed
                </span>
            </h3>
            @forelse($sandbox['webhooks']['recent'] as $del)
                <div class="px-3 py-2 text-xs border-b border-gray-100 flex justify-between gap-2">
                    <span>{{ $del->event?->type }} &rarr; {{ $del->webhook?->displayHost() }}</span>
                    <span>{{ $del->status }}@if($del->last_status_code) ({{ $del->last_status_code }})@endif</span>
                </div>
            @empty
                <p class="px-3 py-3 text-xs text-gray-500">No sandbox webhook deliveries yet. Add a sandbox endpoint under Webhooks.</p>
            @endforelse
        </div>
    </div>
</section>
