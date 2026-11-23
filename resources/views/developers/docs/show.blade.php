@extends('layouts.vendor')
@section('title', $doc['title'].' - XTRA4U')
@push('styles')
    @include('developers.docs._styles')
@endpush
@section('content')
<x-developers.shell :title="$doc['title']" :subtitle="$doc['summary']" tab="docs" :back="['url' => route('developers.docs.index'), 'label' => 'All documentation']">
    <div class="docs-layout">
        <aside class="docs-nav" aria-label="Documentation pages">
            <nav class="docs-nav-list flex gap-1 text-sm">
                @foreach($pages as $slug => $page)
                    <a href="{{ route('developers.docs.show', $slug) }}"
                       @if($slug === $doc['slug']) aria-current="page" @endif
                       class="py-1.5 px-3 rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-violet {{ $slug === $doc['slug'] ? 'bg-violet-50 font-semibold text-brand-violet' : 'text-gray-600 hover:bg-gray-100' }}">{{ $page['title'] }}</a>
                @endforeach
            </nav>
        </aside>

        <article class="docs-article bg-white rounded-xl border border-gray-200 p-5 sm:p-8">
            @if(count($doc['headings']) > 2)
                <nav class="mb-8 rounded-xl bg-gray-50 border border-gray-200 px-4 py-3 text-sm" aria-label="On this page">
                    <div class="text-xs uppercase tracking-wide text-gray-500 mb-1.5">On this page</div>
                    <ul class="grid sm:grid-cols-2 gap-x-6 gap-y-1">
                        @foreach($doc['headings'] as $h)
                            <li><a href="#{{ $h['id'] }}" class="text-gray-700 hover:text-brand-violet hover:underline">{{ $h['text'] }}</a></li>
                        @endforeach
                    </ul>
                </nav>
            @endif

            {{-- Rendered from repository-owned Markdown with raw HTML stripped (see PortalDocs). --}}
            <div class="docs-prose">{!! $doc['html'] !!}</div>
        </article>
    </div>
</x-developers.shell>

<script>
    // Adds a Copy button to every code block. Progressive enhancement only: without JS the blocks are plain <pre>.
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.docs-prose pre').forEach(pre => {
            const wrap = document.createElement('div');
            wrap.className = 'docs-pre';
            pre.parentNode.insertBefore(wrap, pre);
            wrap.appendChild(pre);
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'docs-copy';
            btn.textContent = 'Copy';
            btn.setAttribute('aria-label', 'Copy code to clipboard');
            btn.addEventListener('click', async () => {
                try { await navigator.clipboard.writeText(pre.innerText.replace(/\n$/, '')); btn.textContent = 'Copied'; }
                catch (e) { const r = document.createRange(); r.selectNodeContents(pre); const s = getSelection(); s.removeAllRanges(); s.addRange(r); btn.textContent = 'Press Ctrl+C'; }
                setTimeout(() => btn.textContent = 'Copy', 2200);
            });
            wrap.appendChild(btn);
        });
    });
</script>
@endsection
