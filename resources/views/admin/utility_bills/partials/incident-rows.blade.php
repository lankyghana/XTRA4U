{{-- Compact incident table. Expects $incidents (collection of UtilityBillIncident) and $empty (string). --}}
<x-admin.table :headers="['Issue', 'State', 'First detected', 'Last detected', 'Affected orders', 'Occurrences', '']">
    @forelse ($incidents as $i)
        <tr>
            <td class="font-medium text-gray-900">{{ $i->label() }}<span class="block text-xs font-normal text-gray-500">KiNG FLEXY</span></td>
            <td>
                <x-admin.status :status="$i->isActive() ? 'degraded' : 'resolved'" :label="$i->isActive() ? 'Active' : 'Recovered'" />
                @if (! $i->isActive() && $i->recovery_note)<span class="block text-xs text-gray-500">{{ $i->recovery_note }}</span>@endif
            </td>
            <td class="whitespace-nowrap text-gray-500">{{ $i->first_detected_at?->format('d M H:i') }}</td>
            <td class="whitespace-nowrap text-gray-500">{{ $i->last_detected_at?->format('d M H:i') }}@if ($i->recovered_at)<span class="block text-xs">recovered {{ $i->recovered_at->format('d M H:i') }}</span>@endif</td>
            <td class="tabular-nums">{{ number_format($i->affected_orders) }}</td>
            <td class="tabular-nums text-gray-500">{{ number_format($i->occurrences) }}</td>
            <td><a class="text-brand-violet font-medium hover:underline" href="{{ route('admin.utility-bill-incidents.show', $i) }}">View orders</a></td>
        </tr>
    @empty
        <tr><td colspan="7" class="text-center text-sm text-gray-500 py-6">{{ $empty }}</td></tr>
    @endforelse
</x-admin.table>
