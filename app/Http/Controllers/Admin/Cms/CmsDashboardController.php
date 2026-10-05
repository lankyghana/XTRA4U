<?php

namespace App\Http\Controllers\Admin\Cms;

use App\Http\Controllers\Controller;
use App\Models\Cms\CmsAnnouncement;
use App\Models\Cms\CmsBanner;
use App\Models\Cms\CmsFaq;
use App\Models\Cms\CmsPage;
use App\Models\Cms\CmsSection;
use App\Support\Cms\CmsAdmin;

class CmsDashboardController extends Controller
{
    public function index()
    {
        $now = now();

        $banners = CmsBanner::get(['id', 'is_active', 'starts_at', 'ends_at']);
        $stats = [
            'published_pages' => CmsPage::published()->count(),
            'draft_pages' => CmsPage::where('status', CmsPage::STATUS_DRAFT)->count(),
            'pending_changes' => CmsPage::whereNotNull('draft')->count() + CmsSection::whereNotNull('draft_data')->distinct('page_id')->count('page_id'),
            'live_banners' => $banners->filter(fn ($b) => $b->statusAt($now) === 'live')->count(),
            'scheduled_banners' => $banners->filter(fn ($b) => $b->statusAt($now) === 'scheduled')->count(),
            'live_announcements' => CmsAnnouncement::get()->filter(fn ($a) => $a->statusAt($now) === 'live')->count(),
            'active_faqs' => CmsFaq::where('is_active', true)->count(),
        ];

        // Recent changes across content types: five newest of each, merged. Two lookups for names.
        $recent = collect()
            ->merge(CmsPage::withTrashed()->latest('updated_at')->limit(5)->get()->map(fn ($m) => [
                'type' => $m->isStructured() ? 'Site page' : 'Page', 'title' => $m->title, 'by' => $m->updated_by, 'at' => $m->updated_at,
                'url' => $m->isStructured() ? route('admin.cms.site.edit', $m->slug) : route('admin.cms.pages.edit', $m),
            ]))
            ->merge(CmsBanner::latest('updated_at')->limit(5)->get()->map(fn ($m) => [
                'type' => 'Banner', 'title' => $m->title, 'by' => $m->updated_by, 'at' => $m->updated_at, 'url' => route('admin.cms.banners.edit', $m),
            ]))
            ->merge(CmsAnnouncement::latest('updated_at')->limit(5)->get()->map(fn ($m) => [
                'type' => 'Announcement', 'title' => $m->title, 'by' => $m->updated_by, 'at' => $m->updated_at, 'url' => route('admin.cms.announcements.edit', $m),
            ]))
            ->merge(CmsFaq::latest('updated_at')->limit(5)->get()->map(fn ($m) => [
                'type' => 'FAQ', 'title' => $m->question, 'by' => $m->updated_by, 'at' => $m->updated_at, 'url' => route('admin.cms.faqs.edit', $m),
            ]))
            ->sortByDesc('at')->take(8)->values();

        $names = CmsAdmin::names($recent->pluck('by'));

        return view('admin.cms.dashboard', compact('stats', 'recent', 'names'));
    }
}
