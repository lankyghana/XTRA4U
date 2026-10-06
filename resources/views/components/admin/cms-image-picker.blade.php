@props([
    'name',                 // form field that receives the media id
    'value' => null,        // current media id
    'label' => 'Image',
    'required' => false,
    'hint' => null,
])

{{--
    Choose an image from the CMS media library, or upload a new one without leaving the form.
    The form only ever submits a media id; the server re-checks that it exists.
--}}
@php
    $current = $value ? \App\Models\Cms\CmsMedia::find((int) $value) : null;
@endphp

<div
    x-data="cmsImagePicker({
        id: @js($current?->id),
        url: @js($current?->url()),
        title: @js($current?->original_name),
        pickerUrl: @js(route('admin.cms.media.picker')),
        uploadUrl: @js(route('admin.cms.media.store')),
    })"
    @keydown.escape.window="close()"
    {{ $attributes->merge(['class' => 'space-y-1']) }}
>
    <span class="block text-sm font-semibold text-gray-700">
        {{ $label }}@if ($required) <span class="text-red-600" aria-hidden="true">*</span>@endif
    </span>

    <input type="hidden" name="{{ $name }}" :value="id ?? ''">

    <div class="flex items-center gap-4 rounded-lg border border-gray-200 bg-gray-50 p-3">
        <div class="flex h-20 w-32 flex-shrink-0 items-center justify-center overflow-hidden rounded-md border border-gray-200 bg-white">
            <template x-if="url"><img :src="url" alt="" class="h-full w-full object-cover"></template>
            <template x-if="!url"><span class="text-xs text-gray-400">No image</span></template>
        </div>
        <div class="min-w-0 flex-1">
            <p class="truncate text-sm text-gray-700" x-text="title || (url ? 'Selected image' : 'Nothing selected')"></p>
            <div class="mt-2 flex flex-wrap gap-2">
                <button type="button" class="rounded-lg border border-brand-violet bg-white px-3 py-1.5 text-sm font-medium text-brand-violet hover:bg-brand-violet-soft" @click="open()">
                    <span x-text="url ? 'Change image' : 'Choose image'"></span>
                </button>
                @unless ($required)
                    <button type="button" x-show="url" x-cloak class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-100" @click="clear()">Remove</button>
                @endunless
            </div>
        </div>
    </div>
    @if ($hint)
        <p class="text-xs text-gray-500">{{ $hint }}</p>
    @endif

    {{-- Library modal --}}
    <div x-show="isOpen" x-cloak class="fixed inset-0 z-[70] flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-label="Choose an image">
        <div class="absolute inset-0 bg-brand-dark/60" @click="close()"></div>
        <div class="relative flex max-h-[90vh] w-full max-w-3xl flex-col overflow-hidden rounded-xl bg-white shadow-2xl">
            <div class="flex items-center justify-between border-b border-gray-200 px-5 py-3">
                <h3 class="text-base font-semibold text-gray-900">Media library</h3>
                <button type="button" class="rounded-md p-1.5 text-gray-500 hover:bg-gray-100" aria-label="Close" @click="close()">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <div class="border-b border-gray-200 bg-gray-50 px-5 py-3">
                <label class="flex cursor-pointer flex-wrap items-center gap-3 text-sm">
                    <span class="rounded-lg bg-brand-violet px-3 py-1.5 font-medium text-white hover:bg-brand-violet-deep">Upload new image</span>
                    <input type="file" class="sr-only" accept="image/jpeg,image/png,image/webp" @change="upload($event)">
                    <span class="text-xs text-gray-500">JPEG, PNG or WebP, up to {{ round(config('cms.media.max_kb') / 1024, 1) }} MB.</span>
                </label>
                <p x-show="uploading" x-cloak class="mt-2 text-sm text-gray-600">Uploading&hellip;</p>
                <p x-show="error" x-cloak x-text="error" class="mt-2 text-sm text-red-600" role="alert"></p>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto p-5">
                <p x-show="!loading && items.length === 0" x-cloak class="py-10 text-center text-sm text-gray-500">No images yet. Upload one above.</p>
                <ul class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4">
                    <template x-for="item in items" :key="item.id">
                        <li>
                            <button type="button" class="group block w-full overflow-hidden rounded-lg border border-gray-200 bg-white text-left hover:border-brand-violet focus:outline-none focus:ring-2 focus:ring-brand-violet" @click="choose(item)">
                                <span class="block aspect-[4/3] overflow-hidden bg-gray-100"><img :src="item.url" :alt="item.alt || ''" loading="lazy" class="h-full w-full object-cover"></span>
                                <span class="block truncate px-2 py-1.5 text-xs text-gray-600" x-text="item.name || ('Image ' + item.id)"></span>
                            </button>
                        </li>
                    </template>
                </ul>
                <div class="mt-4 text-center">
                    <button type="button" x-show="next" x-cloak class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100" @click="load()" :disabled="loading">Load more</button>
                </div>
            </div>
        </div>
    </div>
</div>

@once
    @push('scripts')
        <script>
            function cmsImagePicker(opts) {
                return {
                    id: opts.id, url: opts.url, title: opts.title,
                    isOpen: false, items: [], next: 1, loading: false, uploading: false, error: '',
                    open() { this.isOpen = true; if (this.items.length === 0) this.load(); },
                    close() { this.isOpen = false; },
                    clear() { this.id = null; this.url = null; this.title = null; },
                    choose(item) { this.id = item.id; this.url = item.url; this.title = item.name; this.close(); },
                    async load() {
                        if (!this.next || this.loading) return;
                        this.loading = true;
                        try {
                            const res = await fetch(opts.pickerUrl + '?page=' + this.next, { headers: { 'Accept': 'application/json' } });
                            const data = await res.json();
                            this.items.push(...data.items);
                            this.next = data.next;
                        } catch (e) { this.error = 'Could not load the library.'; }
                        this.loading = false;
                    },
                    async upload(event) {
                        const file = event.target.files[0];
                        if (!file) return;
                        this.error = ''; this.uploading = true;
                        const body = new FormData();
                        body.append('file', file);
                        try {
                            const res = await fetch(opts.uploadUrl, {
                                method: 'POST', body,
                                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                            });
                            const data = await res.json();
                            if (!res.ok) {
                                this.error = (data.errors && data.errors.file && data.errors.file[0]) || data.message || 'Upload failed.';
                            } else {
                                this.items.unshift(data);
                                this.choose(data);
                            }
                        } catch (e) { this.error = 'Upload failed. Check your connection and try again.'; }
                        this.uploading = false;
                        event.target.value = '';
                    },
                };
            }
        </script>
    @endpush
@endonce
