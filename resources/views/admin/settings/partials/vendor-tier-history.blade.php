<div>
    <x-card>
        <div class="px-4 py-5 sm:p-6">
            @if($histories->isEmpty())
                <div class="text-center py-10">
                    <svg class="mx-auto h-10 w-10 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                    </svg>
                    <p class="mt-2 text-sm text-gray-500">No tier history yet.</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead>
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Vendor</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Action</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">From Tier</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">To Tier</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">By</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Date</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Notes</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($histories as $history)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3 text-sm">
                                        <div class="font-medium text-gray-900">{{ $history->vendor?->name ?? '—' }}</div>
                                        <div class="text-xs text-gray-400 font-mono">{{ $history->vendor?->vendor_code }}</div>
                                    </td>
                                    <td class="px-4 py-3 text-sm">
                                        @php
                                            $actionColors = [
                                                'promoted' => 'bg-green-100 text-green-800',
                                                'rejected' => 'bg-red-100 text-red-800',
                                                'assigned' => 'bg-blue-100 text-blue-800',
                                                'demoted'  => 'bg-orange-100 text-orange-800',
                                                'reset'    => 'bg-gray-100 text-gray-700',
                                            ];
                                            $color = $actionColors[$history->action] ?? 'bg-gray-100 text-gray-700';
                                        @endphp
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold {{ $color }}">
                                            {{ $history->action_label }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-500">
                                        {{ $history->previousTier?->name ?? '—' }}
                                    </td>
                                    <td class="px-4 py-3 text-sm">
                                        <span class="font-medium text-gray-900">{{ $history->newTier?->name ?? '—' }}</span>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-500">
                                        {{ $history->admin?->name ?? 'System' }}
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-500 whitespace-nowrap">
                                        {{ $history->created_at->format('M d, Y H:i') }}
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-500 max-w-[200px] truncate" title="{{ $history->notes }}">
                                        {{ $history->notes ?? '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="mt-4">
                    {{ $histories->links() }}
                </div>
            @endif
        </div>
    </x-card>
</div>
