{{--
    One-time secret (API key / webhook signing secret). The value is rendered once, exactly as before, in a readable
    box with a Copy button. Nothing is stored or sent anywhere: the button only writes to the clipboard in this tab.
--}}
@props(['value', 'id', 'label' => 'Secret'])

<div x-data="{ copied: false, failed: false,
        async copy() {
            this.failed = false;
            try { await navigator.clipboard.writeText(this.$refs.v.textContent.trim()); this.copied = true; setTimeout(() => this.copied = false, 2500); }
            catch (e) { const r = document.createRange(); r.selectNodeContents(this.$refs.v); const s = getSelection(); s.removeAllRanges(); s.addRange(r); this.failed = true; }
        } }">
    <div class="flex items-stretch gap-2 flex-col sm:flex-row">
        <code id="{{ $id }}" x-ref="v" class="block flex-1 min-w-0 break-all rounded-lg bg-gray-900 text-white px-4 py-3 text-sm font-mono" aria-label="{{ $label }}" tabindex="0" style="user-select: all;">{{ $value }}</code>
        <button type="button" @click="copy()"
                class="shrink-0 inline-flex items-center justify-center px-4 py-2 text-sm font-medium rounded-lg border border-brand-violet text-brand-violet bg-white hover:bg-brand-violet-soft focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-violet">
            <span x-show="!copied">Copy</span><span x-show="copied" x-cloak>Copied</span>
        </button>
    </div>
    <p class="mt-2 text-xs text-gray-500" role="status" aria-live="polite">
        <span x-show="failed" x-cloak>Your browser blocked copying. The {{ strtolower($label) }} is selected: press Ctrl+C (or &#8984;C).</span>
    </p>
</div>
