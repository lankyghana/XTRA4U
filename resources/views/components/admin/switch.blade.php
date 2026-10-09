@props([
    'name',
    'label',
    'checked' => false,
    'form' => null,
    'model' => null,
])

{{--
    Accessible on/off switch for admin forms. A real checkbox (role="switch") sits behind the
    track, so keyboard, label and form submission behave natively. Pair it with a hidden "0"
    input of the same name when an unchecked value must be submitted.
--}}
<label class="relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer items-center">
    <input type="checkbox" role="switch" name="{{ $name }}" value="1" aria-label="{{ $label }}"
        @if ($form) form="{{ $form }}" @endif
        @if ($model) x-model="{{ $model }}" @endif
        @checked($checked)
        class="peer sr-only">
    <span class="absolute inset-0 rounded-full bg-gray-300 transition-colors peer-checked:bg-brand-violet peer-focus-visible:ring-2 peer-focus-visible:ring-brand-violet peer-focus-visible:ring-offset-2" aria-hidden="true"></span>
    <span class="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow transition-transform peer-checked:translate-x-5" aria-hidden="true"></span>
</label>
