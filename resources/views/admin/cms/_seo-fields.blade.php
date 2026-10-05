{{-- Shared SEO fields. Expects $val (closure: field => current value). --}}
<div class="grid gap-4 sm:grid-cols-2">
    <div class="sm:col-span-2">
        <label for="seo_title" class="block text-sm font-semibold text-gray-700">Search engine title</label>
        <input id="seo_title" name="seo_title" type="text" maxlength="160" value="{{ $val('seo_title') }}" placeholder="{{ $titlePlaceholder ?? '' }}" class="mt-1 block w-full">
        @error('seo_title')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
    <div class="sm:col-span-2">
        <label for="meta_description" class="block text-sm font-semibold text-gray-700">Meta description</label>
        <textarea id="meta_description" name="meta_description" rows="2" maxlength="320" class="mt-1 block w-full" placeholder="{{ $descriptionPlaceholder ?? 'Defaults to the start of the content.' }}">{{ $val('meta_description') }}</textarea>
        @error('meta_description')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
    <div>
        <label for="og_title" class="block text-sm font-semibold text-gray-700">Social title</label>
        <input id="og_title" name="og_title" type="text" maxlength="160" value="{{ $val('og_title') }}" placeholder="Defaults to the search engine title" class="mt-1 block w-full">
        @error('og_title')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
    <div>
        <label for="canonical_url" class="block text-sm font-semibold text-gray-700">Canonical address</label>
        <input id="canonical_url" name="canonical_url" type="text" maxlength="255" value="{{ $val('canonical_url') }}" placeholder="Defaults to this page" class="mt-1 block w-full">
        @error('canonical_url')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
    <div class="sm:col-span-2">
        <label for="og_description" class="block text-sm font-semibold text-gray-700">Social description</label>
        <textarea id="og_description" name="og_description" rows="2" maxlength="320" class="mt-1 block w-full" placeholder="Defaults to the meta description">{{ $val('og_description') }}</textarea>
        @error('og_description')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
    <div class="sm:col-span-2">
        <x-admin.cms-image-picker name="og_image_media_id" :value="$val('og_image_media_id')" label="Social sharing image" hint="Shown when the page is shared on WhatsApp, Facebook and similar." />
        @error('og_image_media_id')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
    </div>
    <div class="sm:col-span-2">
        <input type="hidden" name="robots_noindex" value="0">
        <label class="inline-flex items-center gap-2 text-sm text-gray-700">
            <input type="checkbox" name="robots_noindex" value="1" @checked((bool) $val('robots_noindex'))>
            Ask search engines not to list this page (noindex)
        </label>
    </div>
</div>
