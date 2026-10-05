<?php

namespace Tests\Feature\Cms;

use App\Models\Cms\CmsAnnouncement;
use App\Models\Cms\CmsBanner;
use App\Models\Cms\CmsFaq;
use App\Models\Cms\CmsMedia;
use App\Models\Cms\CmsNavigationItem;
use App\Models\Cms\CmsPage;
use App\Models\Cms\CmsSection;
use App\Models\Cms\CmsSetting;
use App\Support\Cms\CmsCache;
use App\Support\Cms\CmsRegistry;
use Illuminate\Support\Facades\Cache;

class CmsSiteEditingTest extends CmsTestCase
{
    private function saveHeroTitle(string $title)
    {
        $hero = CmsRegistry::defaults('home', 'hero');
        $hero['title'] = $title;

        return $this->put(route('admin.cms.site.section', ['home', 'hero']), ['data' => $hero]);
    }

    // ------------------------------------------------ draft / publish / preview

    public function test_section_edits_stay_draft_until_published(): void
    {
        $this->actingAsAdminGuard();

        $this->saveHeroTitle('Welcome to the [[New Era]]')->assertSessionDoesntHaveErrors([], null, 'section_hero');

        // Visitors still see the original.
        $this->get('/')->assertSee('Your Gateway to')->assertDontSee('Welcome to the');

        // Admin preview shows the draft with a clear banner.
        $this->get(route('admin.cms.site.preview', 'home'))
            ->assertOk()->assertSee('Welcome to the')->assertSee('New Era')->assertSee('unpublished draft')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('Cache-Control');

        $this->post(route('admin.cms.site.publish', 'home'))->assertRedirect();

        auth('admin')->logout();
        $this->get('/')->assertSee('Welcome to the')->assertSee('<span style="color: var(--x4-violet);">New Era</span>', false)->assertDontSee('Your Gateway to');
    }

    public function test_publish_records_a_revision_with_the_publisher_and_discard_drops_drafts(): void
    {
        $admin = $this->actingAsAdminGuard();
        $home = CmsPage::where('slug', 'home')->firstOrFail();

        $this->saveHeroTitle('First change');
        $this->post(route('admin.cms.site.discard', 'home'));
        $this->assertNull(CmsSection::where('page_id', $home->id)->where('key', 'hero')->value('draft_data'));

        $this->saveHeroTitle('Second change');
        $this->post(route('admin.cms.site.publish', 'home'));

        $home->refresh();
        $this->assertSame('admin:'.$admin->id, $home->published_by);
        $this->assertSame(2, $home->revisions()->count());
        $this->assertSame('Second change', $home->revisions()->first()->snapshot['sections']['hero']['data']['title']);
        $this->get(route('admin.cms.site.history', 'home'))->assertOk()->assertSee('v2');
    }

    public function test_a_previous_version_can_be_restored_into_the_draft_and_republished(): void
    {
        $this->actingAsAdminGuard();
        $home = CmsPage::where('slug', 'home')->firstOrFail();
        $original = $home->revisions()->firstOrFail();

        $this->saveHeroTitle('Temporary headline');
        $this->post(route('admin.cms.site.publish', 'home'));
        $this->get('/')->assertSee('Temporary headline');

        $this->post(route('admin.cms.site.restore', ['home', $original]))->assertRedirect();
        $this->get('/')->assertSee('Temporary headline');                      // still live until published
        $this->get(route('admin.cms.site.preview', 'home'))->assertSee('Your Gateway to');

        $this->post(route('admin.cms.site.publish', 'home'));
        auth('admin')->logout();
        $this->get('/')->assertSee('Your Gateway to')->assertDontSee('Temporary headline');
    }

    public function test_hiding_and_reordering_sections_changes_the_public_homepage(): void
    {
        $this->actingAsAdminGuard();

        $order = fn () => collect(app(\App\Support\Cms\CmsContent::class)->movableOrder('home'))->values()->all();
        Cache::flush();
        $this->assertSame(['stats', 'services', 'featured', 'how', 'why', 'vendors', 'cta'], $order());

        $this->post(route('admin.cms.site.visibility', ['home', 'how']), ['visible' => 0])->assertRedirect();
        $this->post(route('admin.cms.site.move', ['home', 'cta']), ['direction' => 'up'])->assertRedirect();

        Cache::flush();
        $this->assertSame(['stats', 'services', 'featured', 'why', 'cta', 'vendors'], $order());     // 'how' hidden, 'cta' moved above 'vendors'
        $this->get('/')->assertOk()->assertDontSee('Three steps. Under 5 minutes.');

        $this->post(route('admin.cms.site.visibility', ['home', 'how']), ['visible' => 1]);
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertLessThan(strpos($html, 'Have a Digital Service to Sell?'), strpos($html, 'Ready to Get Started?'));          // cta now above vendors
    }

    public function test_the_trust_strip_can_be_hidden_but_the_hero_cannot(): void
    {
        $this->actingAsAdminGuard();

        $this->post(route('admin.cms.site.visibility', ['home', 'trust']), ['visible' => 0])->assertRedirect();
        $this->get('/')->assertOk()->assertDontSee('Secure &amp; Reliable', false)->assertSee('Your Gateway to');

        $this->post(route('admin.cms.site.visibility', ['home', 'hero']), ['visible' => 0])->assertNotFound();
    }

    public function test_repeaters_enforce_limits_and_drop_blank_rows(): void
    {
        $this->actingAsAdminGuard();
        $hero = CmsRegistry::defaults('home', 'hero');

        $hero['points'] = [['text' => 'One'], ['text' => ''], ['text' => 'Two']];
        $this->put(route('admin.cms.site.section', ['home', 'hero']), ['data' => $hero])->assertSessionDoesntHaveErrors([], null, 'section_hero');
        $this->assertSame([['text' => 'One'], ['text' => 'Two']], CmsSection::where('key', 'hero')->first()->draft_data['points']);

        $hero['points'] = array_fill(0, 6, ['text' => 'x']);
        $this->put(route('admin.cms.site.section', ['home', 'hero']), ['data' => $hero])->assertSessionHasErrorsIn('section_hero');

        $trust = CmsRegistry::defaults('home', 'trust');
        $trust['items'] = [['icon' => 'shield', 'title' => 'Only one', 'desc' => 'x']];
        $this->put(route('admin.cms.site.section', ['home', 'trust']), ['data' => $trust])->assertSessionHasErrorsIn('section_trust');

        $trust['items'] = [['icon' => 'bomb', 'title' => 'a', 'desc' => 'x'], ['icon' => 'shield', 'title' => 'b', 'desc' => 'y']];
        $this->put(route('admin.cms.site.section', ['home', 'trust']), ['data' => $trust])->assertSessionHasErrorsIn('section_trust');
    }

    public function test_the_about_page_is_editable_and_keeps_its_route(): void
    {
        $this->actingAsAdminGuard();
        $about = CmsRegistry::defaults('about', 'hero');
        $about['title'] = 'We are [[Brand New]]';

        $this->put(route('admin.cms.site.section', ['about', 'hero']), ['data' => $about])->assertSessionDoesntHaveErrors([], null, 'section_hero');
        $this->post(route('admin.cms.site.publish', 'about'));

        $this->get('/about')->assertOk()->assertSee('We are')->assertSee('Brand New');
        $this->post(route('admin.cms.site.visibility', ['about', 'values']), ['visible' => 0]);
        $this->get('/about')->assertOk()->assertDontSee('What drives us every day');
        $this->post(route('admin.cms.site.move', ['about', 'values']), ['direction' => 'up'])->assertNotFound();     // About has a fixed layout
    }

    // ------------------------------------------------------------------ SEO

    public function test_seo_fields_fall_back_in_the_documented_order(): void
    {
        $this->actingAsAdminGuard();
        $this->post(route('admin.cms.pages.store'), ['title' => 'Delivery Times', 'slug' => 'delivery-times', 'body' => "Most orders arrive **within minutes** of payment.\n", 'action' => 'publish']);

        $html = $this->get('/p/delivery-times')->assertOk()->getContent();
        $this->assertStringContainsString('<title>Delivery Times</title>', $html);                                         // SEO title -> page title
        $this->assertStringContainsString('<meta property="og:title" content="Delivery Times">', $html);                     // OG title -> SEO title
        $this->assertStringContainsString('<meta name="description" content="Most orders arrive within minutes of payment.">', $html);   // description -> content excerpt
        $this->assertStringContainsString('<meta property="og:description" content="Most orders arrive within minutes of payment.">', $html);
        $this->assertStringContainsString('<link rel="canonical" href="'.url('/p/delivery-times').'">', $html);
        $this->assertStringNotContainsString('noindex', $html);
        $this->assertStringContainsString('<meta name="twitter:card" content="summary">', $html);
    }

    public function test_explicit_seo_values_override_the_defaults(): void
    {
        $this->actingAsAdminGuard();
        $media = CmsMedia::forceCreate(['disk' => 'bundled', 'path' => 'images/storefront/hero-team.jpg', 'filename' => 'h.jpg', 'mime' => 'image/jpeg', 'size' => 1, 'width' => 10, 'height' => 10, 'path' => 'images/storefront/og-test.jpg']);

        $this->post(route('admin.cms.pages.store'), [
            'title' => 'Delivery Times', 'slug' => 'delivery-times', 'body' => 'Body', 'action' => 'publish',
            'seo_title' => 'Fast Delivery | XTRA4U', 'meta_description' => 'Custom description.',
            'og_title' => 'Share title', 'og_description' => 'Share description.', 'og_image_media_id' => $media->id,
            'canonical_url' => 'https://www.xtra4u.example/delivery', 'robots_noindex' => 1,
        ])->assertSessionHasNoErrors();

        $html = $this->get('/p/delivery-times')->assertOk()->getContent();
        $this->assertStringContainsString('<title>Fast Delivery | XTRA4U</title>', $html);
        $this->assertStringContainsString('<meta name="description" content="Custom description.">', $html);
        $this->assertStringContainsString('<meta property="og:title" content="Share title">', $html);
        $this->assertStringContainsString('<meta property="og:description" content="Share description.">', $html);
        $this->assertStringContainsString('<meta property="og:image" content="'.asset('images/storefront/og-test.jpg').'">', $html);
        $this->assertStringContainsString('<meta name="twitter:card" content="summary_large_image">', $html);
        $this->assertStringContainsString('<link rel="canonical" href="https://www.xtra4u.example/delivery">', $html);
        $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $html);
    }

    public function test_homepage_seo_is_editable_and_defaults_to_the_original_values(): void
    {
        $this->get('/')->assertSee('<title>XTRA4U - Your Digital Services Platform</title>', false);

        $this->actingAsAdminGuard();
        $this->put(route('admin.cms.site.seo', 'home'), ['seo_title' => 'XTRA4U | Data & Airtime', 'meta_description' => 'Buy data.', 'robots_noindex' => 0])->assertSessionHasNoErrors();
        $this->get('/')->assertSee('<title>XTRA4U - Your Digital Services Platform</title>', false);      // draft only
        $this->post(route('admin.cms.site.publish', 'home'));

        $this->get('/')->assertSee('<title>XTRA4U | Data &amp; Airtime</title>', false)->assertSee('<meta name="description" content="Buy data.">', false);
    }

    public function test_seo_input_is_validated(): void
    {
        $this->actingAsAdminGuard();

        $this->put(route('admin.cms.site.seo', 'home'), ['seo_title' => str_repeat('a', 161)])->assertSessionHasErrors('seo_title');
        $this->put(route('admin.cms.site.seo', 'home'), ['canonical_url' => 'javascript:alert(1)'])->assertSessionHasErrors('canonical_url');
        $this->put(route('admin.cms.site.seo', 'home'), ['canonical_url' => 'ftp://x.example'])->assertSessionHasErrors('canonical_url');
        $this->put(route('admin.cms.site.seo', 'home'), ['og_image_media_id' => 'abc'])->assertSessionHasErrors('og_image_media_id');
    }

    // ---------------------------------------------------------------- caching

    public function test_public_reads_are_cached_and_do_not_requery_on_every_request(): void
    {
        Cache::flush();
        $this->get('/')->assertOk();

        foreach ([CmsCache::SETTINGS, CmsCache::NAV, CmsCache::BANNERS, CmsCache::ANNOUNCEMENTS, CmsCache::sectionsKey('home')] as $key) {
            $this->assertTrue(Cache::has($key), "$key was not cached");
        }

        \DB::enableQueryLog();
        $this->get('/')->assertOk();
        $cmsQueries = collect(\DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'cms_'))->count();
        \DB::disableQueryLog();

        $this->assertLessThanOrEqual(3, $cmsQueries, 'CMS tables were queried '.$cmsQueries.' times on a warm homepage');
    }

    public function test_writes_invalidate_the_matching_cache_entries_immediately(): void
    {
        Cache::flush();
        $this->get('/');

        // settings
        $this->assertTrue(Cache::has(CmsCache::SETTINGS));
        CmsSetting::where('key', 'footer.description')->first()->update(['value' => 'Fresh blurb']);
        $this->assertFalse(Cache::has(CmsCache::SETTINGS));
        $this->get('/')->assertSee('Fresh blurb');

        // navigation
        $this->assertTrue(Cache::has(CmsCache::NAV));
        CmsNavigationItem::where('location', 'header')->first()->update(['label' => 'Start']);
        $this->assertFalse(Cache::has(CmsCache::NAV));
        $this->get('/')->assertSee('>Start<', false);

        // banners
        $this->assertTrue(Cache::has(CmsCache::BANNERS));
        CmsBanner::first()->update(['title' => 'Renamed slide']);
        $this->assertFalse(Cache::has(CmsCache::BANNERS));
        $this->get('/')->assertSee('alt="Renamed slide"', false);

        // announcements
        $this->assertTrue(Cache::has(CmsCache::ANNOUNCEMENTS));
        CmsAnnouncement::create(['audience' => 'public', 'type' => 'info', 'title' => 'Brand new notice', 'is_active' => true]);
        $this->assertFalse(Cache::has(CmsCache::ANNOUNCEMENTS));
        $this->get('/')->assertSee('Brand new notice');

        // FAQs
        CmsFaq::create(['category' => 'General', 'question' => 'Cached?', 'answer' => 'No.', 'is_active' => true, 'sort_order' => 1]);
        $this->get('/faq')->assertSee('Cached?');
        $this->assertTrue(Cache::has(CmsCache::FAQS));
        CmsFaq::first()->update(['question' => 'Not cached']);
        $this->assertFalse(Cache::has(CmsCache::FAQS));
        $this->get('/faq')->assertSee('Not cached');
    }

    public function test_publishing_a_page_invalidates_only_that_pages_cache(): void
    {
        Cache::flush();
        $this->get('/privacy');
        $this->get('/terms');
        $this->assertTrue(Cache::has(CmsCache::pageKey('privacy')));
        $this->assertTrue(Cache::has(CmsCache::pageKey('terms')));

        $this->actingAsAdminGuard();
        $privacy = CmsPage::where('slug', 'privacy')->firstOrFail();
        $this->put(route('admin.cms.pages.update', $privacy), ['title' => 'Privacy Policy', 'icon' => 'lock', 'body' => "Fresh.\n\n## New\n\nText", 'action' => 'publish']);

        $this->assertFalse(Cache::has(CmsCache::pageKey('privacy')));
        $this->assertTrue(Cache::has(CmsCache::pageKey('terms')));
        auth('admin')->logout();
        $this->get('/privacy')->assertSee('Fresh.');
    }

    public function test_publishing_homepage_sections_invalidates_the_homepage_cache(): void
    {
        $this->actingAsAdminGuard();
        $this->get('/');
        $this->assertTrue(Cache::has(CmsCache::sectionsKey('home')));

        $this->saveHeroTitle('Cache buster');
        $this->post(route('admin.cms.site.publish', 'home'));

        $this->assertFalse(Cache::has(CmsCache::sectionsKey('home')));
        $this->get('/')->assertSee('Cache buster');
    }

    public function test_the_cache_ttl_is_short(): void
    {
        $this->assertLessThanOrEqual(3600, CmsCache::TTL);
    }

    // -------------------------------------------------------- existing routes

    public function test_existing_public_routes_still_work(): void
    {
        foreach (['/', '/about', '/privacy', '/terms', '/order-status', '/results-checkers/', '/vendor/login', '/vendor/request'] as $path) {
            $this->assertContains($this->get($path)->status(), [200, 301, 302], $path);
        }
        $this->get('/')->assertOk()->assertSee('Your Gateway to')->assertSee('Find the Digital Service')->assertSee('Three steps. Under 5 minutes.');
        $this->assertSame(url('/privacy'), route('privacy'));
        $this->assertSame(url('/terms'), route('terms'));
        $this->assertSame(url('/about'), route('about'));
    }

    public function test_the_site_survives_missing_optional_cms_rows(): void
    {
        CmsSection::query()->delete();           // e.g. an optional section row was never created
        Cache::flush();

        $this->get('/')->assertOk()->assertSee('Your Gateway to');
        $this->get('/about')->assertOk()->assertSee('What drives us every day');
    }
}
