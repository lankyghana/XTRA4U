<x-admin-layout :title="$page->title.' - history'" subtitle="Every publish is recorded. Restoring loads a version into the draft; nothing goes live until you publish." :active="$page->isStructured() ? 'cms-'.$page->slug : 'cms-pages'">
    <x-slot name="actions">
        <x-button :href="$backUrl" variant="secondary" size="sm">Back to editor</x-button>
    </x-slot>

    @include('admin.cms._flash')

    <x-admin.table :headers="['Version', 'Event', 'By', 'When', '']">
        @forelse ($revisions as $rev)
            <tr>
                <td class="tabular-nums">v{{ $rev->version }}</td>
                <td>{{ ucfirst($rev->action) }}</td>
                <td class="text-gray-600">{{ $names[$rev->created_by] ?? $rev->created_by_name ?? 'Unknown' }}</td>
                <td class="whitespace-nowrap text-gray-600">{{ $rev->created_at?->format('j M Y, H:i') }}</td>
                <td class="text-right">
                    <form method="POST" action="{{ ($restoreRoute)($rev) }}" onsubmit="return confirm('Load this version into the draft?')">@csrf
                        <x-button type="submit" variant="secondary" size="sm">Restore to draft</x-button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="5"><x-admin.empty title="No history yet" description="A version is saved every time this content is published." /></td></tr>
        @endforelse
    </x-admin.table>
    <div class="mt-4">{{ $revisions->links() }}</div>
</x-admin-layout>
