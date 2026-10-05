{{--
    Informational notices managed under Content > Announcements.
    Purely informational: nothing here changes service availability or any operational state.
    A visitor can dismiss one; that is remembered only in their own browser.
--}}
@props(['audience' => 'public'])

@php
    $notices = $cms->announcements($audience);
    $tones = [
        'info' => ['bg' => 'var(--x4-violet-soft)', 'fg' => 'var(--x4-primary-deep)', 'tw' => 'bg-brand-violet-soft text-brand-violet-deep border-brand-violet/20'],
        'success' => ['bg' => '#dcfce7', 'fg' => '#166534', 'tw' => 'bg-green-50 text-green-800 border-green-600/20'],
        'warning' => ['bg' => '#fef3c7', 'fg' => '#92400e', 'tw' => 'bg-amber-50 text-amber-800 border-amber-600/25'],
    ];
@endphp

@foreach ($notices as $n)
    @php $tone = $tones[$n['type']] ?? $tones['info']; @endphp
    <div
        x-data="{ open: true, key: 'cms-ann-{{ $n['id'] }}' }"
        x-init="try { if (localStorage.getItem(key)) open = false } catch (e) {}"
        x-show="open"
        x-cloak
        role="status"
        data-cms-announcement="{{ $n['id'] }}"
        @if ($audience === 'public')
            style="background-color: {{ $tone['bg'] }}; color: {{ $tone['fg'] }};"
            class="px-5 py-2.5 text-center"
        @else
            class="mb-4 rounded-lg border px-4 py-3 text-sm {{ $tone['tw'] }}"
        @endif
    >
        <div class="mx-auto flex max-w-6xl items-start justify-center gap-3 {{ $audience === 'public' ? 'text-sm' : '' }}">
            <p class="min-w-0">
                <strong class="font-semibold">{{ $n['title'] }}</strong>
                @if ($n['message'])
                    <span class="ml-1">{{ $n['message'] }}</span>
                @endif
                @if ($n['url'] && $n['link_text'])
                    <a href="{{ $n['url'] }}" class="ml-1 font-medium underline"
                       @if (\App\Support\Cms\CmsLink::isExternal($n['url'])) target="_blank" rel="noopener noreferrer" @endif>{{ $n['link_text'] }}</a>
                @endif
            </p>
            <button type="button" class="flex-shrink-0 opacity-70 hover:opacity-100" aria-label="Dismiss announcement"
                    @click="open = false; try { localStorage.setItem(key, '1') } catch (e) {}">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
    </div>
@endforeach
