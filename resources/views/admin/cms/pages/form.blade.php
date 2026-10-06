@php
    $isNew = ! $page->exists;
    $isSystem = (bool) $page->is_system;
    $val = fn (string $f) => old($f, $page->editorValue($f));
@endphp

<x-admin-layout :title="$isNew ? 'New page' : $page->title" :subtitle="$isNew ? 'Write a page, preview it, then publish when ready' : 'Edits are saved as a draft until you publish'" active="cms-pages">
    <x-slot name="actions">
        <x-button :href="route('admin.cms.pages.index')" variant="secondary" size="sm">Back to pages</x-button>
        @unless ($isNew)
            <x-button :href="route('admin.cms.pages.preview', $page)" variant="outline" size="sm" target="_blank" rel="noopener">Preview</x-button>
            <x-button :href="route('admin.cms.pages.history', $page)" variant="ghost" size="sm">History</x-button>
        @endunless
    </x-slot>

    @include('admin.cms._flash')

    @unless ($isNew)
        @if ($page->hasDraft())
            <div class="mb-6 rounded-lg border border-brand-violet/25 bg-brand-violet-soft p-4 text-sm text-brand-violet-deep" role="status">
                <strong>You have unpublished changes.</strong>
                @if ($page->isPublished()) Visitors still see the previous published version. @else This page is not visible to visitors yet. @endif
            </div>
        @endif
    @endunless

    <form method="POST" action="{{ $isNew ? route('admin.cms.pages.store') : route('admin.cms.pages.update', $page) }}" class="grid gap-6 lg:grid-cols-3" novalidate>
        @csrf
        @unless ($isNew) @method('PUT') @endunless

        <div class="space-y-6 lg:col-span-2">
            <section class="space-y-4 rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <div>
                    <label for="title" class="block text-sm font-semibold text-gray-700">Title</label>
                    <input id="title" name="title" type="text" value="{{ $val('title') }}" required maxlength="160" class="mt-1 block w-full">
                    @error('title')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="slug" class="block text-sm font-semibold text-gray-700">Web address</label>
                        @if ($isSystem)
                            <p class="mt-1 rounded-lg bg-gray-50 px-3 py-2 text-sm text-gray-700">{{ parse_url($page->publicUrl(), PHP_URL_PATH) }} <span class="text-xs text-gray-400">(core page, fixed)</span></p>
                        @else
                            <div class="mt-1 flex items-center rounded-lg border border-gray-300 bg-white focus-within:border-brand-violet">
                                <span class="pl-3 text-sm text-gray-500">/p/</span>
                                <input id="slug" name="slug" type="text" value="{{ old('slug', $page->slug) }}" required maxlength="120" class="block w-full !border-0 !shadow-none !pl-0.5 focus:!ring-0" placeholder="refund-policy">
                            </div>
                            <p class="mt-1 text-xs text-gray-500">Lowercase letters, numbers and hyphens. @unless ($isNew) Changing it changes the live address immediately. @endunless</p>
                        @endif
                        @error('slug')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="icon" class="block text-sm font-semibold text-gray-700">Header icon</label>
                        <select id="icon" name="icon" class="mt-1 block w-full">
                            @foreach (\App\Support\Cms\CmsRegistry::ICONS as $icon)
                                <option value="{{ $icon }}" @selected(($val('icon') ?: 'document') === $icon)>{{ ucfirst($icon) }}</option>
                            @endforeach
                        </select>
                        @error('icon')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                </div>
            </section>

            <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <x-admin.cms-markdown name="body" :value="$val('body')" label="Content" :rows="22"
                    hint="Each ## heading becomes a card on the page. Text before the first heading is the highlighted intro; text after a divider is the closing note. Raw HTML is not allowed." />
                @error('body')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </section>

            <section class="space-y-4 rounded-xl border border-gray-200 bg-white p-5 shadow-sm" aria-labelledby="seo-h">
                <div>
                    <h2 id="seo-h" class="text-base font-semibold text-gray-900">Search and social sharing</h2>
                    <p class="text-sm text-gray-500">All optional. Anything left empty falls back automatically (page title, then the start of the content).</p>
                </div>
                @include('admin.cms._seo-fields', ['val' => $val, 'titlePlaceholder' => $val('title')])
            </section>
        </div>

        <aside class="space-y-6">
            <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                <h2 class="text-base font-semibold text-gray-900">Publishing</h2>
                <dl class="mt-3 space-y-1.5 text-sm">
                    <div class="flex justify-between"><dt class="text-gray-500">Status</dt>
                        <dd>@if ($isNew) <x-admin.status status="pending" label="New" /> @elseif ($page->isPublished()) <x-admin.status status="active" label="Published" /> @else <x-admin.status status="pending" label="Draft" /> @endif</dd></div>
                    @if ($page->published_at)
                        <div class="flex justify-between"><dt class="text-gray-500">Published</dt><dd class="text-gray-900">{{ $page->published_at->format('j M Y, H:i') }}</dd></div>
                    @endif
                    @unless ($isNew)
                        <div class="flex justify-between"><dt class="text-gray-500">Last saved</dt><dd class="text-gray-900">{{ $page->updated_at?->diffForHumans() }}</dd></div>
                    @endunless
                </dl>

                <div class="mt-5 flex flex-col gap-2">
                    <x-button type="submit" name="action" value="publish" variant="primary" class="w-full">{{ $isNew ? 'Create and publish' : ($page->isPublished() ? 'Save and publish' : 'Publish') }}</x-button>
                    <x-button type="submit" name="action" value="save" variant="secondary" class="w-full">Save draft</x-button>
                </div>
                <p class="mt-3 text-xs text-gray-500">Saving a draft never changes what visitors see.</p>
            </section>
        </aside>
    </form>

    @unless ($isNew)
        <section class="mt-6 rounded-xl border border-gray-200 bg-white p-5 shadow-sm lg:ml-auto lg:w-1/3" aria-labelledby="more-h">
            <h2 id="more-h" class="text-base font-semibold text-gray-900">More actions</h2>
            <div class="mt-3 flex flex-col gap-2">
                @if ($page->hasDraft())
                    <form method="POST" action="{{ route('admin.cms.pages.discard', $page) }}" onsubmit="return confirm('Discard all unpublished changes to this page?')">@csrf
                        <x-button type="submit" variant="secondary" class="w-full">Discard unpublished changes</x-button>
                    </form>
                @endif
                @if ($page->isPublished() && ! $isSystem)
                    <form method="POST" action="{{ route('admin.cms.pages.unpublish', $page) }}" onsubmit="return confirm('Unpublish this page? Visitors will get a 404.')">@csrf
                        <x-button type="submit" variant="secondary" class="w-full">Unpublish</x-button>
                    </form>
                @endif
                @unless ($isSystem)
                    <form method="POST" action="{{ route('admin.cms.pages.archive', $page) }}" onsubmit="return confirm('Archive this page? It will be hidden and can be restored later.')">@csrf @method('DELETE')
                        <x-button type="submit" variant="danger" class="w-full">Archive page</x-button>
                    </form>
                @else
                    <p class="text-xs text-gray-500">Core pages cannot be unpublished or archived.</p>
                @endunless
            </div>
        </section>
    @endunless
</x-admin-layout>
