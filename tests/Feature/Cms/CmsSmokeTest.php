<?php

namespace Tests\Feature\Cms;

use App\Models\Cms\CmsPage;

class CmsSmokeTest extends CmsTestCase
{
    public function test_every_admin_screen_renders_for_an_admin(): void
    {
        $this->actingAsAdminGuard();
        $page = CmsPage::where('slug', 'privacy')->first();

        foreach ([
            route('admin.cms.dashboard'),
            route('admin.cms.pages.index'),
            route('admin.cms.pages.create'),
            route('admin.cms.pages.edit', $page),
            route('admin.cms.pages.history', $page),
            route('admin.cms.pages.preview', $page),
            route('admin.cms.site.edit', 'home'),
            route('admin.cms.site.edit', 'about'),
            route('admin.cms.site.preview', 'home'),
            route('admin.cms.site.preview', 'about'),
            route('admin.cms.site.history', 'home'),
            route('admin.cms.banners.index'),
            route('admin.cms.banners.create'),
            route('admin.cms.announcements.index'),
            route('admin.cms.announcements.create'),
            route('admin.cms.faqs.index'),
            route('admin.cms.faqs.create'),
            route('admin.cms.media.index'),
            route('admin.cms.media.picker'),
            route('admin.cms.navigation.index'),
            route('admin.cms.settings.edit'),
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }
}
