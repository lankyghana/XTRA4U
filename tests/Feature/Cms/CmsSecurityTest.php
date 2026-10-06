<?php

namespace Tests\Feature\Cms;

use App\Models\Cms\CmsAnnouncement;
use App\Models\Cms\CmsFaq;
use App\Models\Cms\CmsPage;
use App\Models\Cms\CmsSection;
use App\Support\Cms\CmsContent;
use App\Support\Cms\CmsLink;
use App\Support\Cms\CmsMarkdown;

class CmsSecurityTest extends CmsTestCase
{
    // ------------------------------------------------------------ stored XSS

    public function test_markdown_rendering_strips_every_script_vector(): void
    {
        $evil = implode("\n\n", [
            '<script>alert(1)</script>',
            '<img src=x onerror=alert(1)>',
            '<svg onload=alert(1)><circle/></svg>',
            '<iframe src="https://evil.example"></iframe>',
            '<a href="javascript:alert(1)">js link</a>',
            '[md js](javascript:alert(1))',
            '[md data](data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==)',
            '[md vbs](vbscript:msgbox(1))',
            '[tab break](jav&#x09;ascript:alert(1))',
            '![tracking](https://evil.example/pixel.gif)',
            '<div style="background:url(javascript:alert(1))" onclick="x()">styled</div>',
            '<style>body{display:none}</style>',
            '<form action="https://evil.example"><input name=pw></form>',
            '<meta http-equiv="refresh" content="0;url=https://evil.example">',
            '<object data="x.swf"></object><embed src="x.swf">',
            '<math><mtext><script>alert(1)</script></mtext></math>',
            '[fine link](https://ok.example)',
            '<a href="https://ok.example" onclick="alert(1)" style="x">raw anchor</a>',
            '**bold** and *italic* survive',
        ]);

        $html = CmsMarkdown::toHtml($evil);
        $lower = strtolower($html);

        foreach (['<script', 'onerror', 'onload', 'onclick', '<iframe', 'javascript:', 'vbscript:', 'data:text', '<img', '<svg', '<style', '<form', '<input', '<meta', '<object', '<embed', 'style=', 'evil.example/pixel'] as $needle) {
            $this->assertStringNotContainsString($needle, $lower, "Rendered HTML still contains '$needle':\n$html");
        }

        $this->assertStringContainsString('<strong>bold</strong>', $html);
        $this->assertStringContainsString('<em>italic</em>', $html);
        $this->assertStringContainsString('href="https://ok.example"', $html);
        $this->assertStringContainsString('rel="noopener noreferrer nofollow"', $html);
    }

    public function test_the_sanitizer_survives_markup_already_present_in_the_database(): void
    {
        // Even HTML that bypassed the Markdown layer is cleaned by the DOM whitelist.
        $html = CmsMarkdown::sanitize('<p onclick="x()">hi<script>alert(1)</script></p><a href="javascript:alert(1)">x</a><h1>Big</h1>');

        $this->assertSame('<p>hi</p>x<h2>Big</h2>', $html);
    }

    public function test_a_malicious_page_is_rendered_safely_on_the_public_site(): void
    {
        $this->actingAsAdminGuard();
        $this->post(route('admin.cms.pages.store'), [
            'title' => '<script>alert("title")</script>',
            'slug' => 'xss-page',
            'icon' => 'document',
            'body' => "<script>alert('body')</script>\n\n## <img src=x onerror=alert(1)> Heading\n\n[click](javascript:alert(1))",
            'seo_title' => '"><script>alert("seo")</script>',
            'meta_description' => '"><img src=x onerror=alert(1)>',
            'action' => 'publish',
        ])->assertRedirect();

        auth('admin')->logout();
        $response = $this->get('/p/xss-page')->assertOk();
        $body = $response->getContent();

        $this->assertStringNotContainsString('<script>alert', $body);
        $this->assertStringNotContainsString('<img src=x', $body);               // attribute text is escaped (&lt;img ...), never a live tag
        $this->assertStringNotContainsString('javascript:alert', $body);
        $this->assertStringContainsString('&lt;script&gt;alert(&quot;title&quot;)', $body);   // title is escaped, not executed
    }

    public function test_structured_section_text_is_escaped_on_the_homepage(): void
    {
        $this->actingAsAdminGuard();
        $this->put(route('admin.cms.site.section', ['home', 'services']), ['data' => [
            'eyebrow' => '<b>x</b>',
            'title' => '<script>alert(1)</script> [[<img src=x onerror=alert(2)>]]',
            'description' => '"><script>alert(3)</script>',
        ]])->assertSessionHasNoErrors();
        $this->post(route('admin.cms.site.publish', 'home'));

        auth('admin')->logout();
        $body = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert', $body);
        $this->assertStringNotContainsString('<img src=x onerror', $body);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $body);
    }

    public function test_faq_answers_are_sanitized_on_the_public_faq_page(): void
    {
        CmsFaq::create(['category' => 'General', 'question' => '<b>Q?</b>', 'answer' => "<script>alert(1)</script>\n\n[x](javascript:alert(1)) **fine**", 'is_active' => true, 'sort_order' => 1]);

        $body = $this->get('/faq')->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert', $body);
        $this->assertStringNotContainsString('javascript:alert', $body);
        $this->assertStringContainsString('&lt;b&gt;Q?&lt;/b&gt;', $body);
        $this->assertStringContainsString('<strong style="font-weight: 500;">fine</strong>', $body);
    }

    public function test_announcement_text_and_links_are_escaped_and_validated(): void
    {
        $this->actingAsAdminGuard();
        $this->post(route('admin.cms.announcements.store'), [
            'audience' => 'public', 'type' => 'info', 'is_active' => '1',
            'title' => '<script>alert(1)</script>', 'message' => '"><img src=x onerror=alert(1)>',
        ])->assertSessionHasNoErrors();

        auth('admin')->logout();
        $body = $this->get('/')->getContent();
        $this->assertStringNotContainsString('<script>alert(1)', $body);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $body);

        $this->actingAsAdminGuard();
        $this->post(route('admin.cms.announcements.store'), [
            'audience' => 'public', 'type' => 'info', 'title' => 'x', 'link_url' => 'javascript:alert(1)', 'link_text' => 'go',
        ])->assertSessionHasErrors('link_url');
    }

    // ------------------------------------------------------------------ links

    public function test_link_validation_accepts_only_safe_destinations(): void
    {
        foreach (['/about', '/privacy', '/order-status', '/faq', '/p/some-page', '/vendor/login', '/vendor/request', '{shop}', 'https://example.com/path?x=1', 'mailto:help@example.com', 'tel:+233201234567', ''] as $ok) {
            $this->assertNull(CmsLink::validate($ok), "should accept: $ok");
        }

        foreach ([
            'javascript:alert(1)', 'JaVaScRiPt:alert(1)', ' javascript:alert(1)', 'data:text/html,<b>x</b>', 'vbscript:x',
            'http://example.com', 'ftp://example.com', '//evil.example', '/\\evil.example', '\\\\evil', 'https:///nohost', 'https://nodot',
            '/admin', '/admin/login', '/Admin/dashboard', '/admin/content', '/vendor/dashboard', '/vendor/orders', '/vendor/wallet',
            '/api/anything', '/clear-cache/secret', '/storage-link/x', '/csrf-token', '/no-such-page', 'about', 'foo', "/about\nSet-Cookie: x=1", '/about with space',
        ] as $bad) {
            $this->assertNotNull(CmsLink::validate($bad), 'should reject: '.json_encode($bad));
        }
    }

    public function test_render_time_link_filter_drops_unsafe_values_even_if_stored(): void
    {
        $this->assertNull(CmsLink::safe('javascript:alert(1)'));
        $this->assertNull(CmsLink::safe('//evil.example'));
        $this->assertNull(CmsLink::safe('data:text/html,x'));
        $this->assertNull(CmsLink::safe('http://insecure.example'));
        $this->assertSame(url('/about'), CmsLink::safe('/about'));
        $this->assertSame('https://ok.example/x', CmsLink::safe('https://ok.example/x'));
    }

    public function test_unsafe_links_stored_in_a_section_never_reach_the_page(): void
    {
        $page = CmsPage::where('slug', 'home')->firstOrFail();
        $section = CmsSection::where('page_id', $page->id)->where('key', 'hero')->firstOrFail();
        $data = $section->data;
        $data['primary_link'] = 'javascript:alert(1)';
        $data['secondary_link'] = '//evil.example/phish';
        $section->data = $data;
        $section->save();

        $body = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('javascript:alert', $body);
        $this->assertStringNotContainsString('evil.example', $body);
        $this->assertStringContainsString('Buy Now', $body);        // falls back to the safe default destination
    }

    public function test_admin_forms_reject_unsafe_links(): void
    {
        $this->actingAsAdminGuard();

        foreach (['javascript:alert(1)', '//evil.example', '/admin/login', 'http://insecure.example'] as $bad) {
            $this->put(route('admin.cms.site.section', ['home', 'hero']), ['data' => ['primary_link' => $bad, 'primary_text' => 'Go']])
                ->assertSessionHasErrorsIn('section_hero');
        }
    }

    // --------------------------------------------------------- mass assignment

    public function test_privileged_fields_cannot_be_mass_assigned_through_page_forms(): void
    {
        $admin = $this->actingAsAdminGuard();

        $this->post(route('admin.cms.pages.store'), [
            'title' => 'Sneaky', 'slug' => 'sneaky', 'body' => 'x', 'action' => 'save',
            'status' => 'published', 'is_system' => 1, 'kind' => 'home', 'id' => 9999,
            'published_at' => '2000-01-01', 'published_by' => 'admin:1', 'created_by' => 'admin:777', 'updated_by' => 'admin:777',
            'deleted_at' => null, 'draft' => ['title' => 'forced'],
        ])->assertRedirect();

        $page = CmsPage::where('slug', 'sneaky')->firstOrFail();
        $this->assertSame('draft', $page->status);
        $this->assertFalse($page->is_system);
        $this->assertSame('rich', $page->kind);
        $this->assertNotSame(9999, $page->id);
        $this->assertNull($page->published_at);
        $this->assertNull($page->published_by);
        $this->assertSame('admin:'.$admin->id, $page->created_by);
        $this->assertSame('admin:'.$admin->id, $page->updated_by);
        $this->assertArrayNotHasKey('status', $page->draft ?? []);
        $this->assertSame('Sneaky', $page->draft['title']);
    }

    public function test_updates_cannot_flip_status_or_system_flags(): void
    {
        $this->actingAsAdminGuard();
        $this->post(route('admin.cms.pages.store'), ['title' => 'A', 'slug' => 'aaa', 'body' => 'x', 'action' => 'save']);
        $page = CmsPage::where('slug', 'aaa')->firstOrFail();

        $this->put(route('admin.cms.pages.update', $page), [
            'title' => 'A2', 'slug' => 'aaa', 'body' => 'y', 'action' => 'save',
            'status' => 'published', 'is_system' => 1, 'published_at' => now()->toDateTimeString(),
        ]);

        $page->refresh();
        $this->assertSame('draft', $page->status);
        $this->assertFalse($page->is_system);
        $this->assertNull($page->published_at);
        $this->get('/p/aaa')->assertNotFound();
    }

    public function test_section_payloads_only_store_schema_fields(): void
    {
        $this->actingAsAdminGuard();

        $this->put(route('admin.cms.site.section', ['home', 'services']), ['data' => [
            'eyebrow' => 'Services', 'title' => 'T', 'description' => 'D',
            'injected' => '<script>alert(1)</script>', 'is_visible' => 0, 'sort_order' => 99, 'draft_data' => ['x' => 1],
        ]])->assertSessionHasNoErrors();

        $section = CmsSection::whereHas('page', fn ($q) => $q->where('slug', 'home'))->where('key', 'services')->firstOrFail();
        $this->assertSame(['eyebrow', 'title', 'description'], array_keys($section->draft_data));
        $this->assertTrue($section->is_visible);
        $this->assertNotSame(99, $section->sort_order);
    }

    // ------------------------------------------------------ IDOR / forged ids

    public function test_forged_section_and_page_identifiers_are_rejected(): void
    {
        $this->actingAsAdminGuard();

        $this->put(route('admin.cms.site.section', ['home', 'no_such_section']), ['data' => []])->assertNotFound();
        $this->put(route('admin.cms.site.section', ['home', 'values']), ['data' => []])->assertNotFound();     // an About-only section
        $this->get('/admin/content/site/privacy')->assertNotFound();                                          // not a structured page
        $this->post(route('admin.cms.site.visibility', ['home', 'hero']), ['visible' => 0])->assertNotFound();  // hero is not hideable
        $this->post(route('admin.cms.site.move', ['home', 'hero']), ['direction' => 'down'])->assertNotFound(); // hero is not movable
        $this->post(route('admin.cms.site.move', ['home', 'services']), ['direction' => 'sideways'])->assertNotFound();
    }

    public function test_a_revision_of_another_page_cannot_be_restored_onto_this_one(): void
    {
        $this->actingAsAdminGuard();
        $privacy = CmsPage::where('slug', 'privacy')->firstOrFail();
        $terms = CmsPage::where('slug', 'terms')->firstOrFail();
        $foreign = $terms->revisions()->firstOrFail();

        $this->post(route('admin.cms.pages.restore', [$privacy, $foreign]))->assertNotFound();
        $this->assertNull($privacy->fresh()->draft);
    }

    public function test_og_image_must_reference_an_existing_media_row(): void
    {
        $this->actingAsAdminGuard();

        $this->post(route('admin.cms.pages.store'), ['title' => 'T', 'slug' => 'tee', 'body' => 'x', 'og_image_media_id' => 424242, 'action' => 'save'])
            ->assertSessionHasErrors('og_image_media_id');
    }

    // -------------------------------------------------------------- previews

    public function test_draft_content_is_never_reachable_without_an_admin_session(): void
    {
        $this->actingAsAdminGuard();
        $this->post(route('admin.cms.pages.store'), ['title' => 'Secret Plans', 'slug' => 'secret-plans', 'body' => 'TOP SECRET DRAFT', 'action' => 'save']);
        $page = CmsPage::where('slug', 'secret-plans')->firstOrFail();
        $this->put(route('admin.cms.site.section', ['home', 'services']), ['data' => ['eyebrow' => 'E', 'title' => 'LEAKED HOMEPAGE DRAFT', 'description' => 'D']]);
        auth('admin')->logout();

        $this->get('/p/secret-plans')->assertNotFound();
        $this->get('/')->assertOk()->assertDontSee('LEAKED HOMEPAGE DRAFT');

        foreach ([route('admin.cms.pages.preview', $page), route('admin.cms.site.preview', 'home'), route('admin.cms.site.preview', 'about')] as $url) {
            $response = $this->get($url);
            $this->assertContains($response->status(), [302, 403], $url);
            $this->assertStringNotContainsString('TOP SECRET', (string) $response->getContent());
        }

        // Guessing ids does not help a vendor either.
        $this->actingAsVendor();
        foreach ([route('admin.cms.pages.preview', $page), route('admin.cms.site.preview', 'home')] as $url) {
            $this->assertContains($this->get($url)->status(), [302, 403]);
        }
    }

    public function test_preview_mode_does_not_leak_into_later_public_requests(): void
    {
        $this->actingAsAdminGuard();
        $this->put(route('admin.cms.site.section', ['home', 'services']), ['data' => ['eyebrow' => 'E', 'title' => 'DRAFT ONLY TITLE', 'description' => 'D']]);

        $this->get(route('admin.cms.site.preview', 'home'))->assertOk()->assertSee('DRAFT ONLY TITLE', false);

        // The request is over: preview state must be gone, and the next public request sees live content only.
        $this->assertFalse(app(CmsContent::class)->previewing());
        auth('admin')->logout();
        $this->get('/')->assertOk()->assertDontSee('DRAFT ONLY TITLE', false);
    }

    public function test_the_admin_markdown_preview_endpoint_sanitizes_and_requires_admin(): void
    {
        $this->postJson(route('admin.cms.markdown-preview'), ['markdown' => '<script>x</script>**ok**'])->assertStatus(401);

        $this->actingAsAdminGuard();
        $json = $this->postJson(route('admin.cms.markdown-preview'), ['markdown' => '<script>x</script>

**ok** [a](javascript:x)'])->assertOk()->json();

        $this->assertStringNotContainsString('script', $json['html']);
        $this->assertStringNotContainsString('javascript', $json['html']);
        $this->assertStringContainsString('<strong>ok</strong>', $json['html']);
    }

    public function test_cms_never_exposes_unrelated_operational_records(): void
    {
        // The CMS tables hold public content only: guard against someone wiring finance data into them.
        foreach (['cms_pages', 'cms_sections', 'cms_banners', 'cms_announcements', 'cms_faqs', 'cms_media', 'cms_settings', 'cms_navigation_items', 'cms_revisions'] as $table) {
            foreach (\Illuminate\Support\Facades\Schema::getColumnListing($table) as $column) {
                $this->assertDoesNotMatchRegularExpression('/wallet|balance|payment|order_id|commission|withdraw|password|secret|api_key/i', $column, "$table.$column");
            }
        }
        $this->assertSame(0, CmsAnnouncement::count());
    }
}
