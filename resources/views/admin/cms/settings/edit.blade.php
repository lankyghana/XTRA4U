<x-admin-layout title="Site Settings" subtitle="Public contact details, footer text and social links. Never enter passwords or private keys here." active="cms-settings">
    @include('admin.cms._flash')

    <form method="POST" action="{{ route('admin.cms.settings.update') }}" class="max-w-4xl space-y-6" novalidate>
        @csrf
        @method('PUT')

        @foreach ($groups as $group => $fields)
            <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm" aria-labelledby="g-{{ \Illuminate\Support\Str::slug($group) }}">
                <h2 id="g-{{ \Illuminate\Support\Str::slug($group) }}" class="mb-4 text-base font-semibold text-gray-900">{{ $group }}</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    @foreach ($fields as $key => $def)
                        @php
                            $field = \App\Http\Requests\Admin\Cms\CmsSettingsRequest::field($key);
                            $value = old('settings.'.$field, $def['value']);
                        @endphp
                        <div class="{{ $def['type'] === 'textarea' ? 'sm:col-span-2' : '' }}">
                            @if ($def['type'] === 'image')
                                <x-admin.cms-image-picker :name="'settings['.$field.']'" :value="is_numeric($value) ? $value : null" :label="$def['label']" />
                            @else
                            <label for="s-{{ $field }}" class="block text-sm font-semibold text-gray-700">{{ $def['label'] }}</label>
                            @endif
                            @if ($def['type'] === 'image')
                            @elseif ($def['type'] === 'textarea')
                                <textarea id="s-{{ $field }}" name="settings[{{ $field }}]" rows="2" maxlength="{{ $def['max'] }}" class="mt-1 block w-full">{{ $value }}</textarea>
                            @else
                                <input id="s-{{ $field }}" name="settings[{{ $field }}]" type="{{ ['email' => 'email', 'url' => 'url', 'phone' => 'tel'][$def['type']] ?? 'text' }}" maxlength="{{ $def['max'] }}" value="{{ $value }}" class="mt-1 block w-full"
                                       @if ($def['type'] === 'url') placeholder="https://" @endif>
                            @endif
                            @if (! empty($def['help']))<p class="mt-1 text-xs text-gray-500">{{ $def['help'] }}</p>@endif
                            @error('settings.'.$field)<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                        </div>
                    @endforeach
                </div>
                @if ($group === 'Social links')
                    <p class="mt-3 text-xs text-gray-500">Only links you fill in are shown on the website. Leave a field empty to hide that icon.</p>
                @endif
            </section>
        @endforeach

        <div class="flex justify-end">
            <x-button type="submit" variant="primary">Save settings</x-button>
        </div>
    </form>
</x-admin-layout>
