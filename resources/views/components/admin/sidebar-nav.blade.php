@props([
    'groups',          // [groupLabel => [link, ...]] — each link: key, label, href, isActive, badge?
    'mobile' => false, // closes the drawer on navigation
])

<nav {{ $attributes->merge(['class' => 'space-y-5']) }} aria-label="Admin navigation">
    @foreach ($groups as $groupLabel => $links)
        <div>
            <p class="px-3 mb-1.5 text-[11px] font-semibold uppercase tracking-wider text-white/40">{{ $groupLabel }}</p>
            <div class="space-y-0.5">
                @foreach ($links as $link)
                    <a href="{{ $link['href'] }}"
                       @if ($mobile) @click="openSidebar = false" @endif
                       @if ($link['isActive']) aria-current="page" @endif
                       class="group flex items-center rounded-lg px-3 py-2 text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:outline-white {{ $link['isActive'] ? 'bg-brand-violet text-white' : 'text-white/65 hover:bg-white/5 hover:text-white' }}">
                        <svg class="mr-3 h-5 w-5 flex-shrink-0 {{ $link['isActive'] ? 'text-white' : 'text-white/40 group-hover:text-white/70' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            {!! admin_nav_paths($link['key']) !!}
                        </svg>
                        <span class="truncate">{{ $link['label'] }}</span>
                        @if (! empty($link['badge']))
                            <span class="ml-auto inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-red-500 px-1.5 text-[11px] font-semibold text-white" aria-label="{{ $link['badge'] }} unread">{{ $link['badge'] > 99 ? '99+' : $link['badge'] }}</span>
                        @endif
                    </a>
                @endforeach
            </div>
        </div>
    @endforeach
</nav>
