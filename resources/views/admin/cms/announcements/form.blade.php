@php
    $isNew = ! $announcement->exists;
    $dt = fn ($field) => old($field, $announcement->{$field}?->format('Y-m-d\TH:i'));
@endphp

<x-admin-layout :title="$isNew ? 'New announcement' : 'Edit announcement'" subtitle="Shown as a dismissible bar. Informational only." active="cms-announcements">
    <x-slot name="actions">
        <x-button :href="route('admin.cms.announcements.index')" variant="secondary" size="sm">Back to announcements</x-button>
    </x-slot>

    @include('admin.cms._flash')

    <form method="POST" action="{{ $isNew ? route('admin.cms.announcements.store') : route('admin.cms.announcements.update', $announcement) }}" class="max-w-3xl space-y-5 rounded-xl border border-gray-200 bg-white p-5 shadow-sm" novalidate>
        @csrf
        @unless ($isNew) @method('PUT') @endunless

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="audience" class="block text-sm font-semibold text-gray-700">Who sees it</label>
                <select id="audience" name="audience" class="mt-1 block w-full">
                    @foreach (\App\Models\Cms\CmsAnnouncement::AUDIENCES as $key => $label)
                        <option value="{{ $key }}" @selected(old('audience', $announcement->audience) === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="type" class="block text-sm font-semibold text-gray-700">Style</label>
                <select id="type" name="type" class="mt-1 block w-full">
                    @foreach (\App\Models\Cms\CmsAnnouncement::TYPES as $key => $label)
                        <option value="{{ $key }}" @selected(old('type', $announcement->type) === $key)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="sm:col-span-2">
                <label for="title" class="block text-sm font-semibold text-gray-700">Headline</label>
                <input id="title" name="title" type="text" maxlength="160" required value="{{ old('title', $announcement->title) }}" class="mt-1 block w-full" placeholder="MTN maintenance tonight from 11 PM">
            </div>
            <div class="sm:col-span-2">
                <label for="message" class="block text-sm font-semibold text-gray-700">Message <span class="font-normal text-gray-500">(optional)</span></label>
                <textarea id="message" name="message" rows="3" maxlength="600" class="mt-1 block w-full">{{ old('message', $announcement->message) }}</textarea>
            </div>
            <div>
                <label for="link_url" class="block text-sm font-semibold text-gray-700">Link <span class="font-normal text-gray-500">(optional)</span></label>
                <input id="link_url" name="link_url" type="text" maxlength="255" value="{{ old('link_url', $announcement->link_url) }}" class="mt-1 block w-full" placeholder="/faq or https://…">
            </div>
            <div>
                <label for="link_text" class="block text-sm font-semibold text-gray-700">Link text</label>
                <input id="link_text" name="link_text" type="text" maxlength="60" value="{{ old('link_text', $announcement->link_text) }}" class="mt-1 block w-full" placeholder="Learn more">
            </div>
            <div>
                <label for="starts_at" class="block text-sm font-semibold text-gray-700">Start showing</label>
                <input id="starts_at" name="starts_at" type="datetime-local" value="{{ $dt('starts_at') }}" class="mt-1 block w-full">
            </div>
            <div>
                <label for="ends_at" class="block text-sm font-semibold text-gray-700">Stop showing</label>
                <input id="ends_at" name="ends_at" type="datetime-local" value="{{ $dt('ends_at') }}" class="mt-1 block w-full">
            </div>
            <div class="sm:col-span-2">
                <input type="hidden" name="is_active" value="0">
                <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $announcement->is_active))> Active (untick to hide it without deleting)
                </label>
            </div>
        </div>

        <div class="flex justify-end gap-2">
            <x-button :href="route('admin.cms.announcements.index')" variant="secondary">Cancel</x-button>
            <x-button type="submit" variant="primary">{{ $isNew ? 'Create announcement' : 'Save changes' }}</x-button>
        </div>
    </form>
</x-admin-layout>
