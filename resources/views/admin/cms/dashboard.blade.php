<x-admin-layout title="Content" subtitle="Manage the public XTRA4U website without editing code" active="cms">
    @include('admin.cms._flash')

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-admin.stat label="Published pages" :value="$stats['published_pages']" tone="success" :href="route('admin.cms.pages.index', ['status' => 'published'])" />
        <x-admin.stat label="Draft pages" :value="$stats['draft_pages']" tone="warning" :href="route('admin.cms.pages.index', ['status' => 'draft'])" />
        <x-admin.stat label="Pending changes" :value="$stats['pending_changes']" hint="Saved but not published yet" tone="brand" />
        <x-admin.stat label="Active FAQs" :value="$stats['active_faqs']" :href="route('admin.cms.faqs.index')" />
        <x-admin.stat label="Live banners" :value="$stats['live_banners']" tone="success" :href="route('admin.cms.banners.index')" />
        <x-admin.stat label="Scheduled banners" :value="$stats['scheduled_banners']" tone="warning" :href="route('admin.cms.banners.index')" />
        <x-admin.stat label="Live announcements" :value="$stats['live_announcements']" :href="route('admin.cms.announcements.index')" />
    </div>

    <div class="mt-8 grid gap-6 lg:grid-cols-3">
        <section class="lg:col-span-2" aria-labelledby="recent-heading">
            <h2 id="recent-heading" class="mb-3 text-base font-semibold text-gray-900">Recent changes</h2>
            <x-admin.table :headers="['Content', 'Type', 'Changed by', 'When']">
                @forelse ($recent as $row)
                    <tr>
                        <td><a href="{{ $row['url'] }}" class="admin-link">{{ \Illuminate\Support\Str::limit($row['title'], 60) }}</a></td>
                        <td class="text-gray-600">{{ $row['type'] }}</td>
                        <td class="text-gray-600">{{ $names[$row['by']] ?? ($row['by'] === 'system' ? 'System import' : 'Unknown') }}</td>
                        <td class="whitespace-nowrap text-gray-600">{{ $row['at']?->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4"><x-admin.empty title="No changes yet" description="Edits to pages, banners, announcements and FAQs will appear here." /></td></tr>
                @endforelse
            </x-admin.table>
        </section>

        <section aria-labelledby="start-heading">
            <h2 id="start-heading" class="mb-3 text-base font-semibold text-gray-900">Edit the website</h2>
            <div class="divide-y divide-gray-100 rounded-xl border border-gray-200 bg-white text-sm shadow-sm">
                @foreach ([
                    ['Homepage', 'Hero, services, steps, vendor call-to-action', route('admin.cms.site.edit', 'home')],
                    ['About page', 'Mission, values and call-to-action', route('admin.cms.site.edit', 'about')],
                    ['Privacy, Terms and other pages', 'Rich text with draft, preview and history', route('admin.cms.pages.index')],
                    ['Banners', 'Homepage slideshow with start and end dates', route('admin.cms.banners.index')],
                    ['Announcements', 'Notices for visitors or vendors', route('admin.cms.announcements.index')],
                    ['Navigation and footer', 'Menu links, contact details, social links', route('admin.cms.navigation.index')],
                ] as [$label, $desc, $url])
                    <a href="{{ $url }}" class="block px-4 py-3 hover:bg-brand-violet-soft/40">
                        <span class="font-medium text-gray-900">{{ $label }}</span>
                        <span class="block text-xs text-gray-500">{{ $desc }}</span>
                    </a>
                @endforeach
            </div>
            <p class="mt-4 text-xs text-gray-500">
                This area only changes website content. Orders, payments, pricing, wallets and fulfilment are managed in their own sections.
            </p>
        </section>
    </div>
</x-admin-layout>
