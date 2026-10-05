{{--
    One schema-driven field. Expects:
      $field   schema entry from CmsRegistry
      $name    field key
      $value   current value
      $bag     MessageBag holding this section's validation errors
      $id      unique DOM id prefix
--}}
@php
    $inputName = 'data['.$name.']';
    $fieldErrors = collect($bag->getMessages())->filter(fn ($m, $k) => $k === 'data.'.$name || str_starts_with($k, 'data.'.$name.'.'))->flatten();
@endphp

<div class="{{ in_array($field['type'], ['textarea', 'repeater', 'image']) ? 'sm:col-span-2' : '' }}">
    @if ($field['type'] === 'image')
        <x-admin.cms-image-picker :name="$inputName" :value="is_numeric($value) ? $value : null" :label="$field['label']" />
    @elseif ($field['type'] === 'repeater')
        @php
            $rows = array_values(is_array($value) ? $value : []);
            $blank = \App\Support\Cms\CmsRegistry::fieldDefaults($field['fields']);
        @endphp
        <fieldset x-data="cmsRepeater({ rows: @js($rows), blank: @js($blank), max: {{ (int) $field['max'] }}, min: {{ (int) ($field['min'] ?? 0) }} })">
            <legend class="block text-sm font-semibold text-gray-700">{{ $field['label'] }}
                <span class="font-normal text-gray-500">(<span x-text="rows.length"></span> of up to {{ $field['max'] }})</span>
            </legend>
            <div class="mt-2 space-y-3">
                <template x-for="(row, i) in rows" :key="row._k">
                    <div class="rounded-lg border border-gray-200 bg-gray-50 p-3">
                        <div class="grid gap-3 sm:grid-cols-2">
                            @foreach ($field['fields'] as $sub => $subField)
                                <div class="{{ $subField['type'] === 'textarea' ? 'sm:col-span-2' : '' }}">
                                    <label class="block text-xs font-medium text-gray-600">{{ $subField['label'] }}</label>
                                    @if ($subField['type'] === 'select')
                                        <select :name="'data[{{ $name }}]['+i+'][{{ $sub }}]'" x-model="row.{{ $sub }}" class="mt-1 block w-full">
                                            @foreach ($subField['options'] as $opt)
                                                <option value="{{ $opt }}">{{ ucfirst($opt) }}</option>
                                            @endforeach
                                        </select>
                                    @elseif ($subField['type'] === 'textarea')
                                        <textarea :name="'data[{{ $name }}]['+i+'][{{ $sub }}]'" x-model="row.{{ $sub }}" rows="2" maxlength="{{ $subField['max'] ?? 400 }}" class="mt-1 block w-full"></textarea>
                                    @else
                                        <input type="{{ $subField['type'] === 'number' ? 'number' : 'text' }}" @if ($subField['type'] === 'number') step="any" min="0" @endif
                                               :name="'data[{{ $name }}]['+i+'][{{ $sub }}]'" x-model="row.{{ $sub }}" maxlength="{{ $subField['max'] ?? 255 }}" class="mt-1 block w-full">
                                    @endif
                                </div>
                            @endforeach
                        </div>
                        <div class="mt-2 text-right">
                            <button type="button" class="text-xs font-medium text-red-600 hover:underline disabled:opacity-40" :disabled="rows.length <= min" @click="remove(i)">Remove</button>
                        </div>
                    </div>
                </template>
            </div>
            <button type="button" class="mt-3 rounded-lg border border-brand-violet bg-white px-3 py-1.5 text-sm font-medium text-brand-violet hover:bg-brand-violet-soft disabled:opacity-40" :disabled="rows.length >= max" @click="add()">Add another</button>
        </fieldset>
    @else
        <label for="{{ $id }}" class="block text-sm font-semibold text-gray-700">{{ $field['label'] }}</label>
        @if ($field['type'] === 'textarea')
            <textarea id="{{ $id }}" name="{{ $inputName }}" rows="3" maxlength="{{ $field['max'] ?? 400 }}" class="mt-1 block w-full">{{ $value }}</textarea>
        @elseif ($field['type'] === 'select')
            <select id="{{ $id }}" name="{{ $inputName }}" class="mt-1 block w-full">
                @foreach ($field['options'] as $opt)
                    <option value="{{ $opt }}" @selected((string) $value === (string) $opt)>{{ ucfirst($opt) }}</option>
                @endforeach
            </select>
        @elseif ($field['type'] === 'number')
            <input id="{{ $id }}" type="number" step="any" min="0" name="{{ $inputName }}" value="{{ $value }}" class="mt-1 block w-full">
        @else
            <input id="{{ $id }}" type="text" name="{{ $inputName }}" value="{{ $value }}" maxlength="{{ $field['max'] ?? 255 }}" class="mt-1 block w-full"
                   @if ($field['type'] === 'link') placeholder="/page, https://…, or {shop}" inputmode="url" @endif>
        @endif
        @if (! empty($field['hint']))
            <p class="mt-1 text-xs text-gray-500">{{ $field['hint'] }}</p>
        @elseif ($field['type'] === 'link')
            <p class="mt-1 text-xs text-gray-500">A page on this site (like /about), a full https:// address, or {shop} for the main store.</p>
        @endif
    @endif

    @foreach ($fieldErrors as $message)
        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
    @endforeach
</div>
