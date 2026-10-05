<?php

namespace Tests\Feature\Cms;

use App\Models\Cms\CmsAnnouncement;
use App\Models\Cms\CmsBanner;
use App\Models\Cms\CmsFaq;
use App\Models\Cms\CmsMedia;
use App\Models\Cms\CmsNavigationItem;
use App\Models\Cms\CmsSetting;
use App\Support\Cms\CmsContent;
use Illuminate\Support\Facades\Cache;

class CmsContentTypesTest extends CmsTestCase
{
    private function media(string $name = 'x'): CmsMedia
    {
        return CmsMedia::forceCreate(['disk' => 'bundled', 'path' => "images/storefront/$name.jpg", 'filename' => "$name.jpg", 'mime' => 'image/jpeg', 'size' => 1, 'width' => 10, 'height' => 10]);
    }

    private function banner(array $attrs = []): CmsBanner
    {
        return CmsBanner::create($attrs + ['placement' => 'home_hero', 'title' => 'B'.uniqid(), 'image_media_id' => $this->media(uniqid())->id, 'is_active' => true, 'sort_order' => 50]);
    }

    private function liveTitles(): array
    {
        Cache::flush();

        return array_column(app(CmsContent::class)->banners('home_hero'), 'title');
    }

    // ----------------------------------------------------------------- banners

    public function test_only_active_banners_inside_their_window_are_shown_in_order(): void
    {
        CmsBanner::query()->delete();
        $this->banner(['title' => 'second', 'sort_order' => 2]);
        $this->banner(['title' => 'first', 'sort_order' => 1]);
        $this->banner(['title' => 'inactive', 'is_active' => false]);
        $this->banner(['title' => 'scheduled', 'starts_at' => now()->addDay()]);
        $this->banner(['title' => 'expired', 'ends_at' => now()->subMinute()]);
        $this->banner(['title' => 'running', 'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(), 'sort_order' => 3]);

        $this->assertSame(['first', 'second', 'running'], $this->liveTitles());
    }

    public function test_a_banner_expires_automatically_without_any_cache_invalidation(): void
    {
        CmsBanner::query()->delete();
        $this->banner(['title' => 'flash sale', 'ends_at' => now()->addMinutes(10)]);

        $this->assertSame(['flash sale'], $this->liveTitles());            // warms the cache

        $this->travel(11)->minutes();                                       // no write, no flush

        $this->assertSame([], array_column(app(CmsContent::class)->banners('home_hero'), 'title'));
    }

    public function test_a_scheduled_banner_starts_by_itself(): void
    {
        CmsBanner::query()->delete();
        $this->banner(['title' => 'launch', 'starts_at' => now()->addHour()]);

        $this->assertSame([], $this->liveTitles());
        $this->travel(61)->minutes();
        $this->assertSame(['launch'], array_column(app(CmsContent::class)->banners('home_hero'), 'title'));
    }

    public function test_the_homepage_slideshow_shows_live_banners_only(): void
    {
        CmsBanner::query()->delete();
        $this->banner(['title' => 'Visible slide']);
        $this->banner(['title' => 'Hidden slide', 'is_active' => false]);
        $this->banner(['title' => 'Expired slide', 'ends_at' => now()->subDay()]);

        $this->get('/')->assertOk()->assertSee('alt="Visible slide"', false)->assertDontSee('Hidden slide')->assertDontSee('Expired slide');
    }

    public function test_banner_crud_validation_and_ordering(): void
    {
        $this->actingAsAdminGuard();
        $mediaId = $this->media('crud')->id;
        CmsBanner::query()->delete();

        $this->post(route('admin.cms.banners.store'), ['placement' => 'home_hero', 'title' => '', 'image_media_id' => $mediaId])->assertSessionHasErrors('title');
        $this->post(route('admin.cms.banners.store'), ['placement' => 'vendor_dashboard', 'title' => 'x', 'image_media_id' => $mediaId])->assertSessionHasErrors('placement');
        $this->post(route('admin.cms.banners.store'), ['placement' => 'home_hero', 'title' => 'x', 'image_media_id' => 999999])->assertSessionHasErrors('image_media_id');
        $this->post(route('admin.cms.banners.store'), ['placement' => 'home_hero', 'title' => 'x', 'image_media_id' => $mediaId, 'starts_at' => '2030-01-02 10:00', 'ends_at' => '2030-01-01 10:00'])->assertSessionHasErrors('ends_at');

        foreach (['A', 'B', 'C'] as $title) {
            $this->post(route('admin.cms.banners.store'), ['placement' => 'home_hero', 'title' => $title, 'image_media_id' => $mediaId, 'is_active' => 1])->assertSessionHasNoErrors();
        }
        $this->assertSame(['A', 'B', 'C'], CmsBanner::orderBy('sort_order')->pluck('title')->all());

        $c = CmsBanner::where('title', 'C')->first();
        $this->post(route('admin.cms.banners.move', $c), ['direction' => 'up']);
        $this->assertSame(['A', 'C', 'B'], CmsBanner::orderBy('sort_order')->pluck('title')->all());
        $this->post(route('admin.cms.banners.move', $c), ['direction' => 'up']);
        $this->post(route('admin.cms.banners.move', $c), ['direction' => 'up']);        // already first: no-op
        $this->assertSame(['C', 'A', 'B'], CmsBanner::orderBy('sort_order')->pluck('title')->all());
        $this->post(route('admin.cms.banners.move', $c), ['direction' => 'diagonal'])->assertStatus(422);

        $a = CmsBanner::where('title', 'A')->first();
        $this->put(route('admin.cms.banners.update', $a), ['placement' => 'home_hero', 'title' => 'A2', 'image_media_id' => $mediaId, 'is_active' => 0])->assertSessionHasNoErrors();
        $this->assertFalse($a->fresh()->is_active);
        $this->assertSame(['C', 'B'], $this->liveTitles());

        $this->delete(route('admin.cms.banners.destroy', $a))->assertRedirect();
        $this->assertDatabaseMissing('cms_banners', ['id' => $a->id]);
        $this->assertDatabaseHas('cms_media', ['id' => $mediaId]);         // deleting a banner never deletes the image
    }

    public function test_banner_list_shows_statuses(): void
    {
        $this->actingAsAdminGuard();
        CmsBanner::query()->delete();
        $this->banner(['title' => 'Live one']);
        $this->banner(['title' => 'Later one', 'starts_at' => now()->addDays(2)]);
        $this->banner(['title' => 'Over one', 'ends_at' => now()->subDays(2)]);
        $this->banner(['title' => 'Off one', 'is_active' => false]);

        $html = $this->get(route('admin.cms.banners.index'))->assertOk()->getContent();
        foreach (['Live', 'Scheduled', 'Expired', 'Inactive'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
    }

    // ----------------------------------------------------------- announcements

    private function announcement(array $attrs = []): CmsAnnouncement
    {
        return CmsAnnouncement::create($attrs + ['audience' => 'public', 'type' => 'info', 'title' => 'T'.uniqid(), 'is_active' => true]);
    }

    public function test_announcements_respect_audience_flag_and_dates(): void
    {
        CmsAnnouncement::query()->delete();
        $this->announcement(['title' => 'public live']);
        $this->announcement(['title' => 'vendor live', 'audience' => 'vendor']);
        $this->announcement(['title' => 'inactive', 'is_active' => false]);
        $this->announcement(['title' => 'future', 'starts_at' => now()->addDay()]);
        $this->announcement(['title' => 'past', 'ends_at' => now()->subHour()]);
        Cache::flush();
        $cms = app(CmsContent::class);

        $this->assertSame(['public live'], array_column($cms->announcements('public'), 'title'));
        $this->assertSame(['vendor live'], array_column($cms->announcements('vendor'), 'title'));
    }

    public function test_public_announcements_show_on_the_site_and_vendor_ones_only_for_vendors(): void
    {
        CmsAnnouncement::query()->delete();
        $this->announcement(['title' => 'MTN maintenance tonight from 11 PM']);
        $this->announcement(['title' => 'Withdrawals may take longer than usual', 'audience' => 'vendor']);

        foreach (['/', '/about', '/privacy'] as $url) {
            $this->get($url)->assertOk()->assertSee('MTN maintenance tonight from 11 PM')->assertDontSee('Withdrawals may take longer');
        }

        $this->actingAsVendor();
        $this->get(route('vendor.dashboard'))->assertOk()->assertSee('Withdrawals may take longer than usual')->assertDontSee('MTN maintenance tonight');
    }

    public function test_announcements_are_informational_only(): void
    {
        // They never touch service availability or any operational switch.
        $before = \App\Models\Cms\CmsSetting::count();
        $this->actingAsAdminGuard();
        $this->post(route('admin.cms.announcements.store'), ['audience' => 'public', 'type' => 'warning', 'title' => 'All services are down', 'is_active' => 1])->assertSessionHasNoErrors();

        $this->assertSame($before, \App\Models\Cms\CmsSetting::count());
        $this->assertSame([], \Illuminate\Support\Facades\DB::table('settings')->where('key', 'like', '%availability%')->pluck('key')->all());
    }

    public function test_announcement_crud_and_validation(): void
    {
        $this->actingAsAdminGuard();

        $this->post(route('admin.cms.announcements.store'), ['audience' => 'customers', 'type' => 'info', 'title' => 'x'])->assertSessionHasErrors('audience');
        $this->post(route('admin.cms.announcements.store'), ['audience' => 'public', 'type' => 'loud', 'title' => 'x'])->assertSessionHasErrors('type');
        $this->post(route('admin.cms.announcements.store'), ['audience' => 'public', 'type' => 'info', 'title' => ''])->assertSessionHasErrors('title');
        $this->post(route('admin.cms.announcements.store'), ['audience' => 'public', 'type' => 'info', 'title' => 'x', 'link_url' => '/faq'])->assertSessionHasErrors('link_text');
        $this->post(route('admin.cms.announcements.store'), ['audience' => 'public', 'type' => 'info', 'title' => 'x', 'starts_at' => '2030-02-01', 'ends_at' => '2030-01-01'])->assertSessionHasErrors('ends_at');

        $this->post(route('admin.cms.announcements.store'), ['audience' => 'public', 'type' => 'success', 'title' => 'Hello', 'message' => 'World', 'is_active' => 1, 'link_url' => '/about', 'link_text' => 'Read'])->assertSessionHasNoErrors();
        $a = CmsAnnouncement::firstOrFail();
        $this->assertNotNull($a->created_by);

        $this->put(route('admin.cms.announcements.update', $a), ['audience' => 'vendor', 'type' => 'warning', 'title' => 'Changed', 'is_active' => 0])->assertSessionHasNoErrors();
        $this->assertSame('vendor', $a->fresh()->audience);
        $this->assertSame('inactive', $a->fresh()->statusAt());

        $this->get(route('admin.cms.announcements.index'))->assertOk()->assertSee('Changed');
        $this->delete(route('admin.cms.announcements.destroy', $a))->assertRedirect();
        $this->assertDatabaseCount('cms_announcements', 0);
    }

    // -------------------------------------------------------------------- FAQs

    public function test_faq_crud_ordering_and_visibility(): void
    {
        $this->actingAsAdminGuard();

        $this->post(route('admin.cms.faqs.store'), ['category' => 'Payments', 'question' => '', 'answer' => 'a'])->assertSessionHasErrors('question');
        $this->post(route('admin.cms.faqs.store'), ['category' => 'Payments', 'question' => 'q', 'answer' => ''])->assertSessionHasErrors('answer');

        foreach (['One', 'Two', 'Three'] as $q) {
            $this->post(route('admin.cms.faqs.store'), ['category' => 'Payments', 'question' => $q, 'answer' => "Answer $q", 'is_active' => 1])->assertSessionHasNoErrors();
        }
        $this->post(route('admin.cms.faqs.store'), ['category' => '', 'question' => 'General q', 'answer' => 'ga', 'is_active' => 1]);

        $this->assertSame(['One', 'Two', 'Three'], CmsFaq::where('category', 'Payments')->orderBy('sort_order')->pluck('question')->all());
        $this->assertSame('General', CmsFaq::where('question', 'General q')->value('category'));

        $three = CmsFaq::where('question', 'Three')->first();
        $this->post(route('admin.cms.faqs.move', $three), ['direction' => 'up']);
        $this->assertSame(['One', 'Three', 'Two'], CmsFaq::where('category', 'Payments')->orderBy('sort_order')->pluck('question')->all());

        $this->get('/faq')->assertOk()->assertSeeInOrder(['One', 'Three', 'Two'])->assertSee('Answer One')->assertSee('General q');

        $two = CmsFaq::where('question', 'Two')->first();
        $this->put(route('admin.cms.faqs.update', $two), ['category' => 'Payments', 'question' => 'Two', 'answer' => 'Answer Two', 'is_active' => 0])->assertSessionHasNoErrors();
        $this->get('/faq')->assertOk()->assertDontSee('Answer Two');

        $this->put(route('admin.cms.faqs.update', $two), ['category' => 'Vendors', 'question' => 'Two', 'answer' => 'Answer Two', 'is_active' => 1]);
        $this->assertSame('Vendors', $two->fresh()->category);

        $this->delete(route('admin.cms.faqs.destroy', $two))->assertRedirect();
        $this->assertDatabaseMissing('cms_faqs', ['id' => $two->id]);
        $this->get(route('admin.cms.faqs.index'))->assertOk()->assertSee('Payments');
    }

    public function test_faq_page_is_404_until_an_active_faq_exists(): void
    {
        $this->get('/faq')->assertNotFound();

        CmsFaq::create(['category' => 'General', 'question' => 'Hidden', 'answer' => 'x', 'is_active' => false, 'sort_order' => 1]);
        $this->get('/faq')->assertNotFound();

        CmsFaq::create(['category' => 'General', 'question' => 'Shown', 'answer' => 'x', 'is_active' => true, 'sort_order' => 2]);
        $this->get('/faq')->assertOk()->assertSee('Shown')->assertDontSee('Hidden');
    }

    // ------------------------------------------------------- navigation/settings

    private function nav(array $overrides = []): array
    {
        return ['items' => $overrides + [
            'header' => [['label' => 'Home', 'url' => '/', 'visible' => 1], ['label' => 'About', 'url' => '/about', 'visible' => 1]],
            'footer_quick' => [['label' => 'Shop', 'url' => '{shop}', 'visible' => 1]],
            'footer_legal' => [['label' => 'Privacy', 'url' => '/privacy', 'visible' => 1]],
        ]];
    }

    public function test_navigation_is_saved_in_order_and_drives_the_header_and_footer(): void
    {
        $this->actingAsAdminGuard();

        $this->put(route('admin.cms.navigation.update'), $this->nav([
            'header' => [
                ['label' => 'Aardvark', 'url' => '/about', 'visible' => 1],
                ['label' => 'Hidden link', 'url' => '/privacy', 'visible' => 0],
                ['label' => 'Zebra', 'url' => 'https://example.com/z', 'visible' => 1],
            ],
        ]))->assertSessionHasNoErrors();

        $this->assertSame(['Aardvark', 'Hidden link', 'Zebra'], CmsNavigationItem::where('location', 'header')->orderBy('sort_order')->pluck('label')->all());

        auth('admin')->logout();
        $this->get('/')->assertOk()->assertSeeInOrder(['Aardvark', 'Zebra'])->assertDontSee('Hidden link');
    }

    public function test_navigation_rejects_dangerous_or_protected_destinations(): void
    {
        $this->actingAsAdminGuard();

        foreach (['javascript:alert(1)', '/admin', '/admin/content', '//evil.example', 'http://insecure.example', '/vendor/dashboard', '/no/such/page', 'data:text/html,x'] as $bad) {
            $this->put(route('admin.cms.navigation.update'), $this->nav(['header' => [['label' => 'X', 'url' => $bad, 'visible' => 1]]]))
                ->assertSessionHasErrors();
        }

        $this->put(route('admin.cms.navigation.update'), $this->nav(['sidebar' => [['label' => 'X', 'url' => '/about', 'visible' => 1]]]))->assertSessionHasErrors();
        $this->put(route('admin.cms.navigation.update'), $this->nav(['header' => [['label' => '', 'url' => '/about']]]))->assertSessionHasErrors();

        $this->assertSame(11, CmsNavigationItem::count());     // nothing was changed by the rejected requests
    }

    public function test_settings_drive_the_footer_and_social_links_only_show_when_configured(): void
    {
        $this->get('/')->assertOk()->assertDontSee('facebook.com')->assertDontSee('tel:');

        $this->actingAsAdminGuard();
        $this->put(route('admin.cms.settings.update'), ['settings' => [
            'contact__support_phone' => '+233 20 123 4567',
            'contact__support_email' => 'help@xtra4u.example',
            'contact__business_address' => "1 High Street\nAccra",
            'contact__working_hours' => 'Mon-Fri 8am-6pm',
            'footer__description' => 'A new company blurb.',
            'footer__credit_name' => '',
            'social__facebook' => 'https://www.facebook.com/xtra4u',
            'social__instagram' => '',
        ]])->assertSessionHasNoErrors();

        auth('admin')->logout();
        $this->get('/')->assertOk()
            ->assertSee('A new company blurb.')
            ->assertSee('help@xtra4u.example')
            ->assertSee('Mon-Fri 8am-6pm')
            ->assertSee('https://www.facebook.com/xtra4u', false)
            ->assertDontSee('instagram.com')
            ->assertDontSee('Developed by');
    }

    public function test_settings_validation(): void
    {
        $this->actingAsAdminGuard();

        $cases = [
            'contact__support_email' => 'not-an-email',
            'contact__support_phone' => '<script>',
            'social__facebook' => 'javascript:alert(1)',
            'social__facebook ' => 'http://facebook.com/x',
            'social__instagram' => 'https://evil.example/instagram.com',
            'social__x' => 'https://notx.com.evil.example/a',
            'contact__whatsapp_channel_url' => 'https://evil.example/whatsapp.com',
            'footer__description' => str_repeat('a', 241),
        ];
        foreach ($cases as $field => $value) {
            $this->put(route('admin.cms.settings.update'), ['settings' => [trim($field) => $value]])->assertSessionHasErrors('settings.'.trim($field));
        }

        $this->put(route('admin.cms.settings.update'), ['settings' => ['social__youtube' => 'https://youtu.be/abc', 'social__linkedin' => 'https://www.linkedin.com/company/xtra4u']])->assertSessionHasNoErrors();
    }

    public function test_unknown_setting_keys_cannot_be_written(): void
    {
        $this->actingAsAdminGuard();

        $this->put(route('admin.cms.settings.update'), ['settings' => [
            'footer__credit_name' => 'Ok', 'mail__password' => 'x', 'payment__secret' => 'x', 'system__seeded' => 'tampered',
        ]])->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('cms_settings', ['key' => 'mail.password']);
        $this->assertDatabaseMissing('cms_settings', ['key' => 'payment.secret']);
        $this->assertNotSame('tampered', CmsSetting::where('key', 'system.seeded')->value('value'));
    }

    public function test_the_whatsapp_widget_can_be_removed(): void
    {
        $this->get('/')->assertSee('Follow us on WhatsApp');

        $this->actingAsAdminGuard();
        $this->put(route('admin.cms.settings.update'), ['settings' => ['contact__whatsapp_channel_url' => '']])->assertSessionHasNoErrors();

        auth('admin')->logout();
        $this->get('/')->assertOk()->assertDontSee('Follow us on WhatsApp')->assertDontSee('whatsapp.com/channel');
    }
}
