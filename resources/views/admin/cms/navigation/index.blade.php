<x-admin-layout title="Navigation" subtitle="Header menu and footer link columns. Only public pages can be linked." active="cms-navigation">
    @include('admin.cms._flash')

    <form method="POST" action="{{ route('admin.cms.navigation.update') }}" class="space-y-6" novalidate>
        @csrf
        @method('PUT')

        @foreach ($locations as $location => $data)
            @php
                $rows = old('items.'.$location) !== null
                    ? collect(old('items.'.$location))->map(fn ($r) => ['label' => $r['label'] ?? '', 'url' => $r['url'] ?? '', 'visible' => (bool) ($r['visible'] ?? false)])->values()->all()
                    : $data['items'];
                $max = $location === 'header' ? 8 : 10;
            @endphp
            <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm" aria-labelledby="nav-{{ $location }}"
                     x-data="cmsNavList({ rows: @js($rows), max: {{ $max }} })">
                <div class="mb-3 flex items-center justify-between">
                    <h2 id="nav-{{ $location }}" class="text-base font-semibold text-gray-900">{{ $data['label'] }}</h2>
                    <span class="text-xs text-gray-500"><span x-text="rows.length"></span> of {{ $max }}</span>
                </div>

                <div class="space-y-2">
                    <template x-for="(row, i) in rows" :key="row._k">
                        <div class="grid items-center gap-2 rounded-lg border border-gray-200 bg-gray-50 p-2 sm:grid-cols-[1fr_1.4fr_auto_auto]">
                            <div>
                                <label class="sr-only">Label</label>
                                <input type="text" maxlength="60" placeholder="Label" x-model="row.label" :name="'items[{{ $location }}]['+i+'][label]'" class="block w-full">
                            </div>
                            <div>
                                <label class="sr-only">Link</label>
                                <input type="text" maxlength="255" placeholder="/about or https://…" x-model="row.url" :name="'items[{{ $location }}]['+i+'][url]'" class="block w-full">
                            </div>
                            <div class="flex items-center gap-2">
                                <input type="hidden" :name="'items[{{ $location }}]['+i+'][visible]'" value="0">
                                <label class="inline-flex items-center gap-1.5 text-sm text-gray-700">
                                    <input type="checkbox" value="1" x-model="row.visible" :name="'items[{{ $location }}]['+i+'][visible]'"> Show
                                </label>
                            </div>
                            <div class="flex items-center gap-1">
                                <button type="button" class="rounded border border-gray-200 bg-white p-1 text-gray-500 hover:bg-gray-100 disabled:opacity-30" :disabled="i === 0" @click="move(i, -1)" aria-label="Move up">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 15l7-7 7 7"/></svg>
                                </button>
                                <button type="button" class="rounded border border-gray-200 bg-white p-1 text-gray-500 hover:bg-gray-100 disabled:opacity-30" :disabled="i === rows.length - 1" @click="move(i, 1)" aria-label="Move down">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                                </button>
                                <button type="button" class="rounded border border-gray-200 bg-white px-2 py-1 text-xs font-medium text-red-600 hover:bg-red-50" @click="remove(i)">Remove</button>
                            </div>
                        </div>
                    </template>
                    <p x-show="rows.length === 0" x-cloak class="rounded-lg border border-dashed border-gray-300 p-4 text-center text-sm text-gray-500">No links. This menu will be empty on the site.</p>
                </div>

                <button type="button" class="mt-3 rounded-lg border border-brand-violet bg-white px-3 py-1.5 text-sm font-medium text-brand-violet hover:bg-brand-violet-soft disabled:opacity-40" :disabled="rows.length >= max" @click="add()">Add link</button>

                @foreach ($errors->get('items.'.$location.'*') as $messages)
                    @foreach ($messages as $message)<p class="mt-2 text-xs text-red-600">{{ $message }}</p>@endforeach
                @endforeach
            </section>
        @endforeach

        <p class="text-xs text-gray-500">
            Use a path such as <code>/about</code>, a full <code>https://</code> address, or <code>{shop}</code> for the main store. Available pages include <code>/faq</code> (once a FAQ is active) and <code>/p/your-page</code> for pages you create.
            The footer's "For Vendors" column depends on the page being viewed and is not editable here.
        </p>

        <div class="flex justify-end">
            <x-button type="submit" variant="primary">Save navigation</x-button>
        </div>
    </form>

    @push('scripts')
        <script>
            function cmsNavList(opts) {
                let counter = 0;
                const withKey = (row) => Object.assign({ _k: ++counter }, row);
                return {
                    rows: opts.rows.map(withKey), max: opts.max,
                    add() { if (this.rows.length < this.max) this.rows.push(withKey({ label: '', url: '', visible: true })); },
                    remove(i) { this.rows.splice(i, 1); },
                    move(i, d) { const j = i + d; if (j < 0 || j >= this.rows.length) return; const r = this.rows.splice(i, 1)[0]; this.rows.splice(j, 0, r); },
                };
            }
        </script>
    @endpush
</x-admin-layout>
