@props([
    'name',
    'value' => '',
    'rows' => 16,
    'label' => null,
    'hint' => null,
])

{{--
    Formatting editor for page content. The stored value is Markdown; formatting is limited to
    headings, bold, italic, links, lists, quotes. The Preview tab renders through the very
    same server-side sanitizer the public site uses, so what you see is what visitors get.
--}}
<div
    x-data="cmsMarkdown({ previewUrl: @js(route('admin.cms.markdown-preview')) })"
    {{ $attributes->merge(['class' => 'space-y-1']) }}
>
    @if ($label)
        <label for="{{ $name }}" class="block text-sm font-semibold text-gray-700">{{ $label }}</label>
    @endif

    <div class="overflow-hidden rounded-lg border border-gray-300 bg-white focus-within:border-brand-violet focus-within:ring-2 focus-within:ring-brand-violet/20">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 bg-gray-50 px-2 py-1.5">
            <div class="flex flex-wrap items-center gap-1" x-show="tab === 'write'" role="toolbar" aria-label="Formatting">
                <button type="button" class="cms-tb" title="Section heading" @click="heading()"><span class="font-bold">H</span></button>
                <button type="button" class="cms-tb" title="Bold" @click="wrap('**', '**', 'bold text')"><span class="font-bold">B</span></button>
                <button type="button" class="cms-tb" title="Italic" @click="wrap('*', '*', 'italic text')"><span class="italic">I</span></button>
                <button type="button" class="cms-tb" title="Link" @click="link()">Link</button>
                <button type="button" class="cms-tb" title="Bulleted list" @click="lines('- ')">&bull; List</button>
                <button type="button" class="cms-tb" title="Numbered list" @click="lines('1. ')">1. List</button>
                <button type="button" class="cms-tb" title="Quote" @click="lines('> ')">Quote</button>
                <button type="button" class="cms-tb" title="Divider (closing note starts after it)" @click="divider()">&mdash;</button>
            </div>
            <div class="ml-auto inline-flex rounded-md bg-gray-200/70 p-0.5 text-xs font-medium">
                <button type="button" class="rounded px-2.5 py-1" :class="tab === 'write' ? 'bg-white text-brand-violet shadow-sm' : 'text-gray-600'" @click="tab = 'write'">Write</button>
                <button type="button" class="rounded px-2.5 py-1" :class="tab === 'preview' ? 'bg-white text-brand-violet shadow-sm' : 'text-gray-600'" @click="showPreview()">Preview</button>
            </div>
        </div>

        <textarea x-ref="ta" x-show="tab === 'write'" id="{{ $name }}" name="{{ $name }}" rows="{{ $rows }}"
                  class="block w-full resize-y border-0 !rounded-none !shadow-none font-mono text-sm leading-relaxed focus:!ring-0"
                  spellcheck="true">{{ $value }}</textarea>

        <div x-show="tab === 'preview'" x-cloak class="cms-preview min-h-[12rem] p-4">
            <p x-show="busy" class="text-sm text-gray-500">Rendering&hellip;</p>
            <div x-show="!busy" x-html="html"></div>
        </div>
    </div>

    <p class="text-xs text-gray-500">
        {{ $hint ?? 'Use the toolbar, or type Markdown: ## Heading, **bold**, *italic*, [text](https://example.com), - list item, > quote. Raw HTML is not allowed and is removed.' }}
    </p>
</div>

@once
    @push('styles')
        <style>
            .cms-tb { border-radius: .375rem; padding: .25rem .625rem; font-size: .8125rem; line-height: 1.25rem; color: #374151; background: #fff; border: 1px solid #e5e7eb; }
            .cms-tb:hover { background: #ede9fe; color: #4434d4; border-color: #c4b5fd; }
            .cms-preview h2 { font-size: 1.25rem; font-weight: 600; margin: 1.25rem 0 .5rem; }
            .cms-preview h3 { font-size: 1.05rem; font-weight: 600; margin: 1rem 0 .4rem; }
            .cms-preview p { margin: .6rem 0; line-height: 1.65; }
            .cms-preview ul { list-style: disc; padding-left: 1.4rem; margin: .6rem 0; }
            .cms-preview ol { list-style: decimal; padding-left: 1.4rem; margin: .6rem 0; }
            .cms-preview blockquote { border-left: 3px solid #533afd; padding-left: .9rem; color: #4b5563; margin: .8rem 0; }
            .cms-preview a { color: #533afd; text-decoration: underline; }
            .cms-preview hr { border: 0; border-top: 1px solid #e5e7eb; margin: 1.2rem 0; }
        </style>
    @endpush
    @push('scripts')
        <script>
            function cmsMarkdown(opts) {
                return {
                    tab: 'write', html: '', busy: false,
                    get ta() { return this.$refs.ta; },
                    edit(fn) {
                        const el = this.ta, s = el.selectionStart, e = el.selectionEnd;
                        const result = fn(el.value, s, e);
                        el.value = result.value; el.focus();
                        el.setSelectionRange(result.start, result.end);
                        el.dispatchEvent(new Event('input', { bubbles: true }));
                    },
                    wrap(before, after, placeholder) {
                        this.edit((v, s, e) => {
                            const sel = v.slice(s, e) || placeholder;
                            return { value: v.slice(0, s) + before + sel + after + v.slice(e), start: s + before.length, end: s + before.length + sel.length };
                        });
                    },
                    link() {
                        this.edit((v, s, e) => {
                            const sel = v.slice(s, e) || 'link text';
                            const ins = '[' + sel + '](https://)';
                            return { value: v.slice(0, s) + ins + v.slice(e), start: s + sel.length + 3, end: s + sel.length + 11 };
                        });
                    },
                    heading() { this.lines('## '); },
                    lines(prefix) {
                        this.edit((v, s, e) => {
                            const from = v.lastIndexOf('\n', s - 1) + 1;
                            let to = v.indexOf('\n', e); if (to === -1) to = v.length;
                            const block = v.slice(from, to).split('\n').map((l, i) => prefix.startsWith('1.') ? (i + 1) + '. ' + l : prefix + l).join('\n');
                            return { value: v.slice(0, from) + block + v.slice(to), start: from, end: from + block.length };
                        });
                    },
                    divider() {
                        this.edit((v, s, e) => {
                            const ins = '\n\n---\n\n';
                            return { value: v.slice(0, e) + ins + v.slice(e), start: e + ins.length, end: e + ins.length };
                        });
                    },
                    async showPreview() {
                        this.tab = 'preview'; this.busy = true;
                        try {
                            const res = await fetch(opts.previewUrl, {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                                body: JSON.stringify({ markdown: this.ta.value }),
                            });
                            const data = await res.json();
                            this.html = res.ok ? (data.html || '<p class="text-gray-400">Nothing to preview yet.</p>') : '<p class="text-red-600">Preview failed.</p>';
                        } catch (e) { this.html = '<p class="text-red-600">Preview failed.</p>'; }
                        this.busy = false;
                    },
                };
            }
        </script>
    @endpush
@endonce
