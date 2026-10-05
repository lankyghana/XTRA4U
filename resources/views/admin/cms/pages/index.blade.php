<x-admin-layout title="Pages" subtitle="Privacy, Terms and any other information page" active="cms-pages">
    <x-slot name="actions">
        <x-button :href="route('admin.cms.pages.create')" variant="primary" size="sm">New page</x-button>
    </x-slot>

    @include('admin.cms._flash')

    <div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        <x-admin.tabs :items="[
            ['label' => 'All', 'href' => route('admin.cms.pages.index'), 'active' => $tab === 'all', 'count' => $counts['all']],
            ['label' => 'Published', 'href' => route('admin.cms.pages.index', ['status' => 'published']), 'active' => $tab === 'published', 'count' => $counts['published']],
            ['label' => 'Drafts', 'href' => route('admin.cms.pages.index', ['status' => 'draft']), 'active' => $tab === 'draft', 'count' => $counts['draft']],
            ['label' => 'Archived', 'href' => route('admin.cms.pages.index', ['status' => 'archived']), 'active' => $tab === 'archived', 'count' => $counts['archived']],
        ]" />
        <x-admin.filters :action="route('admin.cms.pages.index')" :search="$q" placeholder="Search title or address" :hidden="['status' => $tab === 'all' ? null : $tab]" :reset-url="route('admin.cms.pages.index', $tab === 'all' ? [] : ['status' => $tab])" />
    </div>

    <x-admin.table :headers="['Title', 'Address', 'Status', 'Last changed', '']">
        @forelse ($pages as $page)
            <tr>
                <td>
                    @if ($page->trashed())
                        <span class="text-gray-900">{{ $page->title }}</span>
                    @else
                        <a href="{{ route('admin.cms.pages.edit', $page) }}" class="admin-link">{{ $page->title }}</a>
                    @endif
                    @if ($page->is_system)<span class="ml-1 rounded bg-gray-100 px-1.5 py-0.5 text-[11px] text-gray-600">Core page</span>@endif
                </td>
                <td class="text-gray-600">{{ $page->is_system ? parse_url($page->publicUrl(), PHP_URL_PATH) : '/p/'.$page->slug }}</td>
                <td class="whitespace-nowrap">
                    @if ($page->trashed())
                        <x-admin.status status="cancelled" label="Archived" />
                    @elseif ($page->isPublished())
                        <x-admin.status status="active" label="Published" />
                    @else
                        <x-admin.status status="pending" label="Draft" />
                    @endif
                    @if ($page->hasDraft() && $page->isPublished() && ! $page->trashed())
                        <x-admin.status status="processing" label="Unpublished changes" />
                    @endif
                </td>
                <td class="whitespace-nowrap text-gray-600">
                    {{ $page->updated_at?->diffForHumans() }}
                    <span class="block text-xs text-gray-400">{{ $names[$page->updated_by] ?? '' }}</span>
                </td>
                <td class="whitespace-nowrap text-right">
                    @if ($page->trashed())
                        <form method="POST" action="{{ route('admin.cms.pages.unarchive', $page->id) }}" class="inline">
                            @csrf
                            <x-button type="submit" variant="secondary" size="sm">Restore</x-button>
                        </form>
                    @else
                        <x-button :href="route('admin.cms.pages.edit', $page)" variant="secondary" size="sm">Edit</x-button>
                        @if ($page->isPublished())
                            <x-button :href="$page->publicUrl()" variant="ghost" size="sm" target="_blank" rel="noopener">View</x-button>
                        @endif
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="5"><x-admin.empty title="No pages here" description="Create a page for policies, help information or anything else visitors should read."><x-button :href="route('admin.cms.pages.create')" variant="primary" size="sm">New page</x-button></x-admin.empty></td></tr>
        @endforelse
    </x-admin.table>

    <div class="mt-4">{{ $pages->links() }}</div>
</x-admin-layout>
