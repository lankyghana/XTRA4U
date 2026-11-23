{{-- Request-log rows shared by the dashboard, application page and full log. $time = 'ago' | 'full' --}}
<div class="divide-y divide-gray-100">
    @forelse($rows as $r)
        <div class="px-4 sm:px-5 py-2.5 text-xs flex flex-col sm:flex-row sm:items-center sm:justify-between gap-1 sm:gap-3">
            <span class="min-w-0 break-all text-gray-700">
                <b class="font-mono text-gray-900">{{ $r->method }}</b> <span class="font-mono">{{ $r->path }}</span>
                @if(($r->environment ?? null) === 'sandbox')<x-badge size="sm" variant="pending">test</x-badge>@endif
                @if($r->request_id)<span class="text-gray-400 font-mono">{{ $r->request_id }}</span>@endif
            </span>
            <span class="flex items-center gap-2 text-gray-600">
                <x-badge size="sm" :variant="$r->status_code >= 400 ? 'failed' : 'completed'">{{ $r->status_code }}</x-badge>
                @if($r->error_code)<span class="font-mono">{{ $r->error_code }}</span>@endif
                <span>{{ $r->duration_ms }}ms &middot; {{ ($time ?? 'ago') === 'full' ? $r->created_at->toDateTimeString() : $r->created_at->diffForHumans() }}</span>
            </span>
        </div>
    @empty
        <p class="px-4 sm:px-5 py-8 text-center text-sm text-gray-500">{{ $empty ?? 'No requests yet' }}</p>
    @endforelse
</div>
