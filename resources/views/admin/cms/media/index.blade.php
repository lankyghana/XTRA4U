<x-admin-layout title="Media" subtitle="Images used by the public website. Images that are in use cannot be deleted." active="cms-media">
    @include('admin.cms._flash')

    <form method="POST" action="{{ route('admin.cms.media.store') }}" enctype="multipart/form-data" class="mb-6 rounded-xl border border-gray-200 bg-white p-4 shadow-sm" novalidate>
        @csrf
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
            <div class="flex-1">
                <label for="file" class="block text-sm font-semibold text-gray-700">Upload an image</label>
                <input id="file" name="file" type="file" accept="image/jpeg,image/png,image/webp" required class="mt-1 block w-full !p-1.5">
                <p class="mt-1 text-xs text-gray-500">JPEG, PNG or WebP, up to {{ round(config('cms.media.max_kb') / 1024, 1) }} MB and {{ config('cms.media.max_dimension') }} px. SVG is not accepted. Files are re-encoded and renamed on upload.</p>
                @error('file')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
            <div class="sm:w-72">
                <label for="alt_text" class="block text-sm font-semibold text-gray-700">Description <span class="font-normal text-gray-500">(optional)</span></label>
                <input id="alt_text" name="alt_text" type="text" maxlength="255" class="mt-1 block w-full" value="{{ old('alt_text') }}">
            </div>
            <x-button type="submit" variant="primary">Upload</x-button>
        </div>
    </form>

    @if ($items->isEmpty())
        <x-admin.empty title="No images yet" description="Upload your first image above." />
    @else
        <ul class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
            @foreach ($items as $item)
                @php $inUse = isset($used[$item->id]); @endphp
                <li class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                    <div class="aspect-[4/3] bg-gray-100"><img src="{{ $item->url() }}" alt="{{ $item->alt_text }}" loading="lazy" class="h-full w-full object-cover"></div>
                    <div class="space-y-2 p-3">
                        <p class="truncate text-sm font-medium text-gray-900" title="{{ $item->original_name }}">{{ $item->original_name ?? $item->filename }}</p>
                        <p class="text-xs text-gray-500">{{ $item->width }} x {{ $item->height }} &middot; {{ number_format($item->size / 1024) }} KB</p>
                        <div class="flex flex-wrap gap-1">
                            @if ($item->isBundled())<span class="rounded bg-gray-100 px-1.5 py-0.5 text-[11px] text-gray-600">Ships with site</span>@endif
                            @if ($inUse)<span class="rounded bg-brand-violet-soft px-1.5 py-0.5 text-[11px] text-brand-violet-deep">In use</span>@endif
                        </div>
                        <form method="POST" action="{{ route('admin.cms.media.update', $item) }}" class="flex gap-1">@csrf @method('PUT')
                            <label class="sr-only" for="alt-{{ $item->id }}">Description</label>
                            <input id="alt-{{ $item->id }}" name="alt_text" type="text" maxlength="255" value="{{ $item->alt_text }}" placeholder="Description" class="!min-h-8 !py-1 !text-xs w-full">
                            <button type="submit" class="rounded-lg border border-gray-300 px-2 text-xs font-medium text-gray-700 hover:bg-gray-100">Save</button>
                        </form>
                        @if ($item->isBundled() || $inUse)
                            <p class="text-xs text-gray-400">{{ $item->isBundled() ? 'Cannot be deleted.' : 'Remove it from where it is used to delete.' }}</p>
                        @else
                            <form method="POST" action="{{ route('admin.cms.media.destroy', $item) }}" onsubmit="return confirm('Delete this image permanently?')">@csrf @method('DELETE')
                                <button type="submit" class="text-xs font-medium text-red-600 hover:underline">Delete</button>
                            </form>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
        <div class="mt-6">{{ $items->links() }}</div>
    @endif
</x-admin-layout>
