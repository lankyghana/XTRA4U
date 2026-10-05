<?php

namespace Tests\Feature\Cms;

use App\Models\Admin;
use App\Models\Cms\CmsPage;
use App\Models\Cms\CmsRevision;

class CmsPagesTest extends CmsTestCase
{
    private function createPage(array $overrides = [], string $action = 'save')
    {
        return $this->post(route('admin.cms.pages.store'), $overrides + [
            'title' => 'Refund Policy',
            'slug' => 'refund-policy',
            'icon' => 'document',
            'body' => "Intro text.\n\n## Refunds\n\nWe refund within **7 days**.\n\n- Item one\n- Item two",
            'action' => $action,
        ]);
    }

    public function test_creating_a_page_saves_a_draft_that_visitors_cannot_see(): void
    {
        $admin = $this->actingAsAdminGuard();

        $this->createPage()->assertRedirect();

        $page = CmsPage::where('slug', 'refund-policy')->firstOrFail();
        $this->assertSame('draft', $page->status);
        $this->assertSame('rich', $page->kind);
        $this->assertFalse($page->is_system);
        $this->assertSame('admin:'.$admin->id, $page->created_by);
        $this->assertSame('admin:'.$admin->id, $page->updated_by);

        auth('admin')->logout();
        $this->get('/p/refund-policy')->assertNotFound();
    }

    public function test_publishing_makes_the_page_public_and_records_who_and_when(): void
    {
        $admin = $this->actingAsAdminGuard();
        $this->createPage();
        $page = CmsPage::where('slug', 'refund-policy')->firstOrFail();

        $this->post(route('admin.cms.pages.publish', $page))->assertRedirect();

        $page->refresh();
        $this->assertSame('published', $page->status);
        $this->assertNotNull($page->published_at);
        $this->assertSame('admin:'.$admin->id, $page->published_by);
        $this->assertNull($page->draft);

        auth('admin')->logout();
        $this->get('/p/refund-policy')
            ->assertOk()
            ->assertSee('Refund Policy')
            ->assertSee('We refund within', false)
            ->assertSee('<strong style="font-weight: 500;">7 days</strong>', false);
    }

    public function test_create_and_publish_in_one_step(): void
    {
        $this->actingAsAdminGuard();

        $this->createPage(action: 'publish');

        $this->assertSame('published', CmsPage::where('slug', 'refund-policy')->value('status'));
        $this->assertSame(1, CmsRevision::count() - 4); // 4 seeded system revisions + this one
    }

    public function test_editing_a_published_page_changes_only_the_draft_until_published(): void
    {
        $this->actingAsAdminGuard();
        $this->createPage(action: 'publish');
        $page = CmsPage::where('slug', 'refund-policy')->firstOrFail();

        $this->put(route('admin.cms.pages.update', $page), [
            'title' => 'Refund Policy (new)', 'slug' => 'refund-policy', 'icon' => 'document',
            'body' => "## Refunds\n\nNEW TEXT", 'action' => 'save',
        ])->assertRedirect();

        $page->refresh();
        $this->assertSame('Refund Policy', $page->title);          // live columns untouched
        $this->assertStringContainsString('7 days', $page->body);
        $this->assertSame('Refund Policy (new)', $page->draft['title']);

        $this->get('/p/refund-policy')->assertSee('7 days', false)->assertDontSee('NEW TEXT');

        // Preview shows the draft to an admin.
        $this->get(route('admin.cms.pages.preview', $page))
            ->assertOk()->assertSee('NEW TEXT', false)->assertSee('not published', false)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

        $this->post(route('admin.cms.pages.publish', $page));
        $this->get('/p/refund-policy')->assertSee('NEW TEXT', false)->assertDontSee('7 days');
    }

    public function test_publish_appends_a_revision_and_it_can_be_restored_into_the_draft(): void
    {
        $this->actingAsAdminGuard();
        $this->createPage(action: 'publish');
        $page = CmsPage::where('slug', 'refund-policy')->firstOrFail();
        $first = $page->revisions()->first();

        $this->put(route('admin.cms.pages.update', $page), [
            'title' => 'Refund Policy', 'slug' => 'refund-policy', 'icon' => 'document',
            'body' => 'Second version', 'action' => 'publish',
        ]);
        $this->assertSame(2, $page->revisions()->count());

        $this->post(route('admin.cms.pages.restore', [$page, $first]))->assertRedirect();

        $page->refresh();
        $this->assertStringContainsString('We refund within', $page->draft['body']);
        $this->assertStringContainsString('Second version', $page->body);   // live is unchanged until publish
        $this->get(route('admin.cms.pages.history', $page))->assertOk()->assertSee('v2')->assertSee('v1');
    }

    public function test_unpublish_hides_the_page_again(): void
    {
        $this->actingAsAdminGuard();
        $this->createPage(action: 'publish');
        $page = CmsPage::where('slug', 'refund-policy')->firstOrFail();

        $this->post(route('admin.cms.pages.unpublish', $page))->assertRedirect();

        $this->assertSame('draft', $page->fresh()->status);
        $this->get('/p/refund-policy')->assertNotFound();
    }

    public function test_core_pages_cannot_be_unpublished_or_archived(): void
    {
        $this->actingAsAdminGuard();
        $privacy = CmsPage::where('slug', 'privacy')->firstOrFail();

        $this->from(route('admin.cms.pages.edit', $privacy))
            ->post(route('admin.cms.pages.unpublish', $privacy))->assertSessionHasErrors('page');
        $this->delete(route('admin.cms.pages.archive', $privacy))->assertSessionHasErrors('page');

        $this->assertSame('published', $privacy->fresh()->status);
        $this->assertNull($privacy->fresh()->deleted_at);
        $this->get('/privacy')->assertOk();
    }

    public function test_archive_is_a_soft_delete_and_can_be_restored(): void
    {
        $this->actingAsAdminGuard();
        $this->createPage(action: 'publish');
        $page = CmsPage::where('slug', 'refund-policy')->firstOrFail();

        $this->delete(route('admin.cms.pages.archive', $page))->assertRedirect(route('admin.cms.pages.index'));

        $this->assertSoftDeleted('cms_pages', ['id' => $page->id]);
        $this->get('/p/refund-policy')->assertNotFound();
        $this->get(route('admin.cms.pages.index', ['status' => 'archived']))->assertOk()->assertSee('Refund Policy');

        $this->post(route('admin.cms.pages.unarchive', $page->id))->assertRedirect();
        $this->assertNotSoftDeleted('cms_pages', ['id' => $page->id]);
        $this->assertSame('draft', $page->fresh()->status);      // restored as a draft, not silently live
    }

    public function test_slugs_must_be_unique_well_formed_and_not_reserved(): void
    {
        $this->actingAsAdminGuard();
        $this->createPage();

        $this->createPage()->assertSessionHasErrors('slug');                                   // duplicate
        $this->createPage(['slug' => 'Has Spaces'])->assertSessionHasErrors('slug');
        $this->createPage(['slug' => '../etc/passwd'])->assertSessionHasErrors('slug');
        $this->createPage(['slug' => 'a--b'])->assertSessionHasErrors('slug');
        foreach (['admin', 'privacy', 'terms', 'about', 'storage', 'p', 'faq'] as $reserved) {
            $this->createPage(['slug' => $reserved])->assertSessionHasErrors('slug');
        }

        // An archived page still owns its slug (the unique index spans soft-deleted rows).
        $page = CmsPage::where('slug', 'refund-policy')->firstOrFail();
        $this->delete(route('admin.cms.pages.archive', $page));
        $this->createPage()->assertSessionHasErrors('slug');
    }

    public function test_slug_of_a_core_page_cannot_be_changed(): void
    {
        $this->actingAsAdminGuard();
        $privacy = CmsPage::where('slug', 'privacy')->firstOrFail();

        $this->put(route('admin.cms.pages.update', $privacy), [
            'title' => 'Privacy Policy', 'slug' => 'hijacked', 'icon' => 'lock', 'body' => 'Changed', 'action' => 'save',
        ])->assertRedirect();

        $this->assertSame('privacy', $privacy->fresh()->slug);
    }

    public function test_validation_errors_are_reported(): void
    {
        $this->actingAsAdminGuard();

        $this->createPage(['title' => ''])->assertSessionHasErrors('title');
        $this->createPage(['icon' => 'not-an-icon'])->assertSessionHasErrors('icon');
        $this->createPage(['canonical_url' => 'javascript:alert(1)'])->assertSessionHasErrors('canonical_url');
        $this->createPage(['og_image_media_id' => 99999])->assertSessionHasErrors('og_image_media_id');
        $this->createPage(['body' => str_repeat('a', 100001)])->assertSessionHasErrors('body');
    }

    public function test_existing_legal_routes_still_work_and_come_from_the_cms(): void
    {
        $this->get('/privacy')->assertOk()->assertSee('Privacy Policy')->assertSee('Information We Collect');
        $this->get('/terms')->assertOk()->assertSee('Terms of Service')->assertSee('Account Termination');
        $this->get('/about')->assertOk()->assertSee('About Us');

        $this->actingAsAdminGuard();
        $privacy = CmsPage::where('slug', 'privacy')->firstOrFail();
        $this->put(route('admin.cms.pages.update', $privacy), [
            'title' => 'Privacy Policy', 'icon' => 'lock', 'action' => 'publish',
            'body' => "Updated intro.\n\n## Brand New Heading\n\nBody.",
        ])->assertRedirect();

        auth('admin')->logout();
        $this->get('/privacy')->assertOk()->assertSee('Brand New Heading')->assertDontSee('Information We Collect');
    }

    public function test_p_route_redirects_system_slugs_to_their_real_urls(): void
    {
        $this->get('/p/privacy')->assertRedirect(route('privacy'));
        $this->get('/p/about')->assertRedirect(route('about'));
        $this->get('/p/does-not-exist')->assertNotFound();
        $this->get('/p/Bad_Slug')->assertNotFound();
    }

    public function test_structured_pages_are_not_editable_through_the_rich_page_screens(): void
    {
        $this->actingAsAdminGuard();
        $home = CmsPage::where('slug', 'home')->firstOrFail();

        $this->get(route('admin.cms.pages.edit', $home))->assertNotFound();
        $this->post(route('admin.cms.pages.publish', $home))->assertNotFound();
        $this->get('/p/home')->assertRedirect(route('storefront.index'));
    }

    public function test_the_page_list_supports_filters_and_search(): void
    {
        $this->actingAsAdminGuard();
        $this->createPage(['title' => 'Alpha Page', 'slug' => 'alpha'], 'publish');
        $this->createPage(['title' => 'Beta Draft', 'slug' => 'beta']);

        $this->get(route('admin.cms.pages.index', ['status' => 'draft']))->assertSee('Beta Draft')->assertDontSee('Alpha Page');
        $this->get(route('admin.cms.pages.index', ['q' => 'alp']))->assertSee('Alpha Page')->assertDontSee('Beta Draft');
        $this->get(route('admin.cms.pages.index', ['q' => '%']))->assertOk()->assertDontSee('Alpha Page');
    }

    public function test_actor_names_resolve_for_both_admin_representations(): void
    {
        $admin = Admin::factory()->create(['name' => 'Ama Admin']);
        $this->actingAs($admin, 'admin');
        $this->createPage(action: 'publish');

        $this->get(route('admin.cms.dashboard'))->assertSee('Ama Admin');
        $this->get(route('admin.cms.pages.history', CmsPage::where('slug', 'refund-policy')->first()))->assertSee('Ama Admin');
    }
}
