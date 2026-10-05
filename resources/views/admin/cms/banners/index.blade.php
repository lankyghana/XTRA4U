<x-admin-layout title="Banners" subtitle="Homepage slideshow images. Expired and not-yet-started banners are hidden automatically." active="cms-banners">
    <x-slot name="actions">
        <x-button :href="route('admin.cms.banners.create')" variant="primary" size="sm">New banner</x-button>
    </x-slot>

    @include('admin.cms._flash')

    @php
        $statusMap = ['live' => ['active', 'Live'], 'scheduled' => ['pending', 'Scheduled'], 'expired' => ['expired', 'Expired'], 'inactive' => ['cancelled', 'Inactive']];
    @endphp

    <x-admin.table :headers="['Image', 'Title', 'Placement', 'Status', 'Runs', 'Order', '']">
        @forelse ($banners as $banner)
            @php [$tone, $label] = $statusMap[$banner->statusAt()]; @endphp
            <tr>
                <td><img src="{{ $banner->image?->url() }}" alt="" class="h-12 w-20 rounded object-cover"></td>
                <td>
                    <a href="{{ route('admin.cms.banners.edit', $banner) }}" class="admin-link">{{ $banner->title }}</a>
                    <span class="block text-xs text-gray-400">{{ $names[$banner->updated_by] ?? '' }}</span>
                </td>
                <td class="text-gray-600">{{ \App\Models\Cms\CmsBanner::PLACEMENTS[$banner->placement] ?? $banner->placement }}</td>
                <td><x-admin.status :status="$tone" :label="$label" /></td>
                <td class="whitespace-nowrap text-xs text-gray-600">
                    {{ $banner->starts_at ? $banner->starts_at->format('j M Y, H:i') : 'Immediately' }}
                    <span class="text-gray-400">to</span>
                    {{ $banner->ends_at ? $banner->ends_at->format('j M Y, H:i') : 'No end date' }}
                </td>
                <td class="whitespace-nowrap">
                    @foreach (['up' => 'Move up', 'down' => 'Move down'] as $dir => $aria)
                        <form method="POST" action="{{ route('admin.cms.banners.move', $banner) }}" class="inline">@csrf
                            <input type="hidden" name="direction" value="{{ $dir }}">
                            <button type="submit" class="rounded border border-gray-200 p-1 text-gray-500 hover:bg-gray-100" aria-label="{{ $aria }}: {{ $banner->title }}">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $dir === 'up' ? 'M5 15l7-7 7 7' : 'M19 9l-7 7-7-7' }}"/></svg>
                            </button>
                        </form>
                    @endforeach
                </td>
                <td class="whitespace-nowrap text-right">
                    <x-button :href="route('admin.cms.banners.edit', $banner)" variant="secondary" size="sm">Edit</x-button>
                    <form method="POST" action="{{ route('admin.cms.banners.destroy', $banner) }}" class="inline" onsubmit="return confirm('Delete this banner? The image stays in the media library.')">@csrf @method('DELETE')
                        <x-button type="submit" variant="ghost" size="sm" class="!text-red-600">Delete</x-button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="7"><x-admin.empty title="No banners" description="Add an image to show it in the homepage slideshow."><x-button :href="route('admin.cms.banners.create')" variant="primary" size="sm">New banner</x-button></x-admin.empty></td></tr>
        @endforelse
    </x-admin.table>

    <p class="mt-3 text-xs text-gray-500">Slides appear in the order shown here. Times use the server time zone ({{ config('app.timezone') }}).</p>
</x-admin-layout>
