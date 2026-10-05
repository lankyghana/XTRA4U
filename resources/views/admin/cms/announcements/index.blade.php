<x-admin-layout title="Announcements" subtitle="Informational notices for visitors or vendors. They never change service availability." active="cms-announcements">
    <x-slot name="actions">
        <x-button :href="route('admin.cms.announcements.create')" variant="primary" size="sm">New announcement</x-button>
    </x-slot>

    @include('admin.cms._flash')

    @php
        $statusMap = ['live' => ['active', 'Live'], 'scheduled' => ['pending', 'Scheduled'], 'expired' => ['expired', 'Expired'], 'inactive' => ['cancelled', 'Inactive']];
    @endphp

    <x-admin.table :headers="['Announcement', 'Audience', 'Style', 'Status', 'Runs', '']">
        @forelse ($announcements as $a)
            @php [$tone, $label] = $statusMap[$a->statusAt()]; @endphp
            <tr>
                <td>
                    <a href="{{ route('admin.cms.announcements.edit', $a) }}" class="admin-link">{{ $a->title }}</a>
                    @if ($a->message)<span class="block max-w-md truncate text-xs text-gray-500">{{ $a->message }}</span>@endif
                </td>
                <td class="text-gray-600">{{ \App\Models\Cms\CmsAnnouncement::AUDIENCES[$a->audience] ?? $a->audience }}</td>
                <td class="text-gray-600">{{ \App\Models\Cms\CmsAnnouncement::TYPES[$a->type] ?? $a->type }}</td>
                <td><x-admin.status :status="$tone" :label="$label" /></td>
                <td class="whitespace-nowrap text-xs text-gray-600">
                    {{ $a->starts_at ? $a->starts_at->format('j M Y, H:i') : 'Immediately' }}
                    <span class="text-gray-400">to</span>
                    {{ $a->ends_at ? $a->ends_at->format('j M Y, H:i') : 'No end date' }}
                    <span class="block text-gray-400">{{ $names[$a->updated_by] ?? '' }}</span>
                </td>
                <td class="whitespace-nowrap text-right">
                    <x-button :href="route('admin.cms.announcements.edit', $a)" variant="secondary" size="sm">Edit</x-button>
                    <form method="POST" action="{{ route('admin.cms.announcements.destroy', $a) }}" class="inline" onsubmit="return confirm('Delete this announcement?')">@csrf @method('DELETE')
                        <x-button type="submit" variant="ghost" size="sm" class="!text-red-600">Delete</x-button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="6"><x-admin.empty title="No announcements" description="Post a notice such as planned maintenance or a change in payout timing."><x-button :href="route('admin.cms.announcements.create')" variant="primary" size="sm">New announcement</x-button></x-admin.empty></td></tr>
        @endforelse
    </x-admin.table>
    <div class="mt-4">{{ $announcements->links() }}</div>
</x-admin-layout>
