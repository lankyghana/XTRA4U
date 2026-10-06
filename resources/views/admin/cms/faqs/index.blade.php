<x-admin-layout title="FAQs" subtitle="Questions are shown on the public /faq page, grouped by category" active="cms-faqs">
    <x-slot name="actions">
        @if ($faqs->flatten()->where('is_active', true)->isNotEmpty())
            <x-button :href="route('cms.faq')" variant="ghost" size="sm" target="_blank" rel="noopener">View page</x-button>
        @endif
        <x-button :href="route('admin.cms.faqs.create')" variant="primary" size="sm">New FAQ</x-button>
    </x-slot>

    @include('admin.cms._flash')

    @forelse ($faqs as $category => $items)
        <section class="mb-6" aria-labelledby="cat-{{ $loop->index }}">
            <div class="mb-2 flex items-center justify-between">
                <h2 id="cat-{{ $loop->index }}" class="text-base font-semibold text-gray-900">{{ $category }}</h2>
                <x-button :href="route('admin.cms.faqs.create', ['category' => $category])" variant="ghost" size="sm">Add to {{ $category }}</x-button>
            </div>
            <x-admin.table :headers="['Question', 'Status', 'Order', '']">
                @foreach ($items as $faq)
                    <tr>
                        <td><a href="{{ route('admin.cms.faqs.edit', $faq) }}" class="admin-link">{{ $faq->question }}</a></td>
                        <td class="whitespace-nowrap">@if ($faq->is_active)<x-admin.status status="active" label="Active" />@else<x-admin.status status="cancelled" label="Inactive" />@endif</td>
                        <td class="whitespace-nowrap">
                            @foreach (['up' => 'Move up', 'down' => 'Move down'] as $dir => $aria)
                                <form method="POST" action="{{ route('admin.cms.faqs.move', $faq) }}" class="inline">@csrf
                                    <input type="hidden" name="direction" value="{{ $dir }}">
                                    <button type="submit" class="rounded border border-gray-200 p-1 text-gray-500 hover:bg-gray-100" aria-label="{{ $aria }}: {{ $faq->question }}">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $dir === 'up' ? 'M5 15l7-7 7 7' : 'M19 9l-7 7-7-7' }}"/></svg>
                                    </button>
                                </form>
                            @endforeach
                        </td>
                        <td class="whitespace-nowrap text-right">
                            <x-button :href="route('admin.cms.faqs.edit', $faq)" variant="secondary" size="sm">Edit</x-button>
                            <form method="POST" action="{{ route('admin.cms.faqs.destroy', $faq) }}" class="inline" onsubmit="return confirm('Delete this FAQ permanently? To hide it instead, edit it and untick Active.')">@csrf @method('DELETE')
                                <x-button type="submit" variant="ghost" size="sm" class="!text-red-600">Delete</x-button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </x-admin.table>
        </section>
    @empty
        <x-admin.empty title="No FAQs yet" description="Add the questions customers and vendors ask most. The public /faq page appears as soon as one is active.">
            <x-button :href="route('admin.cms.faqs.create')" variant="primary" size="sm">New FAQ</x-button>
        </x-admin.empty>
    @endforelse

    <p class="mt-2 text-xs text-gray-500">To link to the FAQ page from the menu or footer, add <code>/faq</code> under Navigation.</p>
</x-admin-layout>
