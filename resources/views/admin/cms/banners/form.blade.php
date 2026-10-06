@php
    $isNew = ! $banner->exists;
    $dt = fn ($field) => old($field, $banner->{$field}?->format('Y-m-d\TH:i'));
@endphp

<x-admin-layout :title="$isNew ? 'New banner' : 'Edit banner'" subtitle="Banners show on the public site only between their start and end times" active="cms-banners">
    <x-slot name="actions">
        <x-button :href="route('admin.cms.banners.index')" variant="secondary" size="sm">Back to banners</x-button>
    </x-slot>

    @include('admin.cms._flash')

    <form method="POST" action="{{ $isNew ? route('admin.cms.banners.store') : route('admin.cms.banners.update', $banner) }}" class="max-w-3xl space-y-5 rounded-xl border border-gray-200 bg-white p-5 shadow-sm" novalidate>
        @csrf
        @unless ($isNew) @method('PUT') @endunless

        <x-admin.cms-image-picker name="image_media_id" :value="old('image_media_id', $banner->image_media_id)" label="Image" :required="true" hint="Wide images (about 1100 x 620) look best in the slideshow." />

        <div class="grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <label for="title" class="block text-sm font-semibold text-gray-700">Title</label>
                <input id="title" name="title" type="text" maxlength="160" required value="{{ old('title', $banner->title) }}" class="mt-1 block w-full">
                <p class="mt-1 text-xs text-gray-500">Describes the image for screen readers and search engines.</p>
            </div>
            <div>
                <label for="placement" class="block text-sm font-semibold text-gray-700">Placement</label>
                <select id="placement" name="placement" class="mt-1 block w-full">
                    @foreach (\App\Models\Cms\CmsBanner::PLACEMENTS as $key => $label)
                        <option value="{{ $key }}" @selected(old('placement', $banner->placement) === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex items-end">
                <input type="hidden" name="is_active" value="0">
                <label class="inline-flex items-center gap-2 pb-2 text-sm text-gray-700">
                    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $banner->is_active))> Active
                </label>
            </div>
            <div>
                <label for="starts_at" class="block text-sm font-semibold text-gray-700">Start showing</label>
                <input id="starts_at" name="starts_at" type="datetime-local" value="{{ $dt('starts_at') }}" class="mt-1 block w-full">
                <p class="mt-1 text-xs text-gray-500">Leave empty to start immediately.</p>
            </div>
            <div>
                <label for="ends_at" class="block text-sm font-semibold text-gray-700">Stop showing</label>
                <input id="ends_at" name="ends_at" type="datetime-local" value="{{ $dt('ends_at') }}" class="mt-1 block w-full">
                <p class="mt-1 text-xs text-gray-500">Leave empty to show until you turn it off.</p>
            </div>
        </div>

        <div class="flex justify-end gap-2">
            <x-button :href="route('admin.cms.banners.index')" variant="secondary">Cancel</x-button>
            <x-button type="submit" variant="primary">{{ $isNew ? 'Create banner' : 'Save changes' }}</x-button>
        </div>
    </form>
</x-admin-layout>
