<?php

namespace Tests\Feature\Cms;

use App\Models\Cms\CmsBanner;
use App\Models\Cms\CmsMedia;
use App\Models\Cms\CmsNavigationItem;
use App\Models\Cms\CmsPage;
use App\Models\Cms\CmsSection;
use App\Models\Cms\CmsSetting;
use App\Support\Cms\CmsContent;
use App\Support\Cms\CmsDefaults;
use App\Support\Cms\CmsRegistry;

class CmsBackfillTest extends CmsTestCase
{
    public function test_migration_backfills_the_previous_site_content(): void
    {
        $this->assertTrue(CmsSetting::where('key', CmsContent::SEEDED_KEY)->exists());

        foreach (['home', 'about', 'privacy', 'terms'] as $slug) {
            $page = CmsPage::where('slug', $slug)->firstOrFail();
            $this->assertTrue($page->isPublished());
            $this->assertTrue($page->is_system);
            $this->assertSame(1, $page->revisions()->count());
        }

        $this->assertSame(count(CmsRegistry::sections('home')), CmsSection::whereHas('page', fn ($q) => $q->where('slug', 'home'))->count());
        $this->assertSame(count(CmsRegistry::sections('about')), CmsSection::whereHas('page', fn ($q) => $q->where('slug', 'about'))->count());
        $this->assertSame(2, CmsBanner::count());
        $this->assertSame(11, CmsNavigationItem::count());
        $this->assertSame('Where trust meets value.', substr(CmsSetting::where('key', 'footer.description')->value('value'), -24));
        $this->assertTrue(CmsMedia::where('disk', 'bundled')->count() >= 6);
    }

    public function test_install_is_idempotent_and_never_overwrites_admin_edits(): void
    {
        CmsSetting::where('key', 'footer.description')->update(['value' => 'Edited by an admin']);

        // Even if the marker were lost, a re-run must keep existing rows.
        CmsSetting::where('key', CmsContent::SEEDED_KEY)->delete();
        CmsDefaults::install();
        CmsDefaults::install();

        $this->assertSame('Edited by an admin', CmsSetting::where('key', 'footer.description')->value('value'));
        $this->assertSame(1, CmsPage::where('slug', 'privacy')->count());
        $this->assertSame(2, CmsBanner::count());
        $this->assertSame(11, CmsNavigationItem::count());
    }

    public function test_unseeded_environment_falls_back_to_registry_defaults(): void
    {
        CmsSetting::query()->delete();
        CmsBanner::query()->delete();
        CmsNavigationItem::query()->delete();
        \Illuminate\Support\Facades\Cache::flush();

        $cms = app(CmsContent::class);

        $this->assertFalse($cms->seeded());
        $this->assertCount(2, $cms->banners('home_hero'));
        $this->assertSame('Home', $cms->nav('header')[0]['label']);
        $this->assertStringContainsString('whatsapp.com/channel', $cms->setting('contact.whatsapp_channel_url'));
    }

    public function test_once_seeded_deleting_every_banner_does_not_resurrect_defaults(): void
    {
        CmsBanner::query()->delete();
        \Illuminate\Support\Facades\Cache::flush();

        $this->assertSame([], app(CmsContent::class)->banners('home_hero'));
    }
}
