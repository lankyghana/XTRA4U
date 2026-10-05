<?php

namespace Tests\Feature\Cms;

use App\Models\Cms\CmsBanner;
use App\Models\Cms\CmsMedia;
use App\Models\Cms\CmsPage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class CmsMediaTest extends CmsTestCase
{
    private function upload(UploadedFile $file, array $extra = [])
    {
        return $this->post(route('admin.cms.media.store'), ['file' => $file] + $extra);
    }

    private function png(int $w = 120, int $h = 80): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, 80, 60, 250));
        ob_start();
        imagepng($im);
        imagedestroy($im);

        return (string) ob_get_clean();
    }

    public function test_a_valid_image_is_stored_under_a_server_generated_name(): void
    {
        $admin = $this->actingAsAdminGuard();

        $this->upload(UploadedFile::fake()->image('Holiday Photo (1).jpg', 640, 360), ['alt_text' => 'A photo'])->assertRedirect();

        $media = CmsMedia::where('disk', 'public')->firstOrFail();
        $this->assertMatchesRegularExpression('#^cms/\d{4}/\d{2}/[0-9a-z]{26}\.jpg$#', $media->path);
        $this->assertSame('image/jpeg', $media->mime);
        $this->assertSame([640, 360], [$media->width, $media->height]);
        $this->assertSame('A photo', $media->alt_text);
        $this->assertSame('admin:'.$admin->id, $media->created_by);
        $this->assertStringNotContainsString('Holiday', $media->path);
        Storage::disk('public')->assertExists($media->path);
    }

    public function test_png_and_webp_are_accepted(): void
    {
        $this->actingAsAdminGuard();

        $this->upload(UploadedFile::fake()->createWithContent('logo.png', $this->png()))->assertSessionHasNoErrors();
        $this->assertSame('image/png', CmsMedia::where('disk', 'public')->latest('id')->value('mime'));

        if (function_exists('imagewebp')) {
            $im = imagecreatetruecolor(100, 100);
            ob_start();
            imagewebp($im);
            $webp = (string) ob_get_clean();
            $this->upload(UploadedFile::fake()->createWithContent('pic.webp', $webp))->assertSessionHasNoErrors();
            $this->assertSame('image/webp', CmsMedia::where('disk', 'public')->latest('id')->value('mime'));
        }
    }

    public function test_a_php_file_disguised_as_a_jpeg_is_rejected(): void
    {
        $this->actingAsAdminGuard();

        $this->upload(UploadedFile::fake()->createWithContent('shell.jpg', '<?php echo shell_exec($_GET["c"]); ?>'))
            ->assertSessionHasErrors('file');
        $this->upload(UploadedFile::fake()->createWithContent('shell.php.jpg', "GIF89a<?php system('id'); ?>"))
            ->assertSessionHasErrors('file');

        $this->assertSame(0, CmsMedia::where('disk', 'public')->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_mime_spoofing_with_a_lying_extension_and_content_type_is_rejected(): void
    {
        $this->actingAsAdminGuard();

        // Real PNG bytes named .php, and text named .png: only the sniffed bytes matter.
        $this->upload(UploadedFile::fake()->createWithContent('image.php', 'plain text pretending to be an image'))->assertSessionHasErrors('file');
        $this->upload(UploadedFile::fake()->createWithContent('image.png', 'plain text pretending to be an image'))->assertSessionHasErrors('file');
        $this->upload(new UploadedFile($this->tmp('hello world'), 'photo.png', 'image/png', null, true))->assertSessionHasErrors('file');

        $this->assertSame(0, CmsMedia::where('disk', 'public')->count());
    }

    public function test_svg_html_pdf_and_gif_are_rejected(): void
    {
        $this->actingAsAdminGuard();

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(1)</script></svg>';
        $this->upload(UploadedFile::fake()->createWithContent('x.svg', $svg))->assertSessionHasErrors('file');
        $this->upload(UploadedFile::fake()->createWithContent('x.png', $svg))->assertSessionHasErrors('file');
        $this->upload(UploadedFile::fake()->createWithContent('x.jpg', '<html><script>alert(1)</script></html>'))->assertSessionHasErrors('file');
        $this->upload(UploadedFile::fake()->createWithContent('x.pdf', "%PDF-1.4\n1 0 obj<<>>endobj"))->assertSessionHasErrors('file');
        $this->upload(UploadedFile::fake()->create('x.gif', 10, 'image/gif'))->assertSessionHasErrors('file');

        $this->assertSame(0, CmsMedia::where('disk', 'public')->count());
    }

    public function test_a_polyglot_has_its_payload_destroyed_by_reencoding(): void
    {
        $this->actingAsAdminGuard();
        $polyglot = $this->png().'<?php system($_GET["c"]); ?>';

        $this->upload(UploadedFile::fake()->createWithContent('poly.png', $polyglot))->assertSessionHasNoErrors();

        $stored = Storage::disk('public')->get(CmsMedia::where('disk', 'public')->firstOrFail()->path);
        $this->assertStringNotContainsString('<?php', $stored);
        $this->assertStringNotContainsString('system(', $stored);
        $this->assertNotFalse(@getimagesizefromstring($stored));
    }

    public function test_exif_metadata_is_stripped(): void
    {
        $this->actingAsAdminGuard();
        $im = imagecreatetruecolor(60, 60);
        ob_start();
        imagejpeg($im);
        $jpeg = (string) ob_get_clean();
        // Inject an APP1/EXIF segment carrying a marker string right after the SOI.
        $payload = "Exif\0\0SECRET-GPS-MARKER";
        $segment = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;
        $withExif = substr($jpeg, 0, 2).$segment.substr($jpeg, 2);

        $this->upload(UploadedFile::fake()->createWithContent('gps.jpg', $withExif))->assertSessionHasNoErrors();

        $stored = Storage::disk('public')->get(CmsMedia::where('disk', 'public')->firstOrFail()->path);
        $this->assertStringNotContainsString('SECRET-GPS-MARKER', $stored);
    }

    public function test_oversized_files_and_dimensions_are_rejected(): void
    {
        $this->actingAsAdminGuard();

        config(['cms.media.max_kb' => 50]);
        $this->upload(UploadedFile::fake()->create('big.jpg', 200, 'image/jpeg'))->assertSessionHasErrors('file');

        config(['cms.media.max_kb' => 5120, 'cms.media.max_dimension' => 300]);
        $this->upload(UploadedFile::fake()->image('wide.png', 400, 100))->assertSessionHasErrors('file');

        config(['cms.media.max_dimension' => 5000, 'cms.media.max_megapixels' => 0.01]);
        $this->upload(UploadedFile::fake()->image('many.png', 400, 400))->assertSessionHasErrors('file');

        $this->upload(UploadedFile::fake()->image('tiny.png', 8, 8))->assertSessionHasErrors('file');

        $this->assertSame(0, CmsMedia::where('disk', 'public')->count());
    }

    public function test_a_missing_file_is_a_validation_error(): void
    {
        $this->actingAsAdminGuard();

        $this->post(route('admin.cms.media.store'), [])->assertSessionHasErrors('file');
        $this->post(route('admin.cms.media.store'), ['file' => 'not-a-file'])->assertSessionHasErrors('file');
    }

    public function test_a_hostile_client_filename_never_influences_the_stored_path(): void
    {
        $this->actingAsAdminGuard();

        $this->upload(UploadedFile::fake()->image('../../../public/index.php.png', 100, 100))->assertSessionHasNoErrors();
        $this->upload(UploadedFile::fake()->image('..\\..\\evil<script>.png', 100, 100))->assertSessionHasNoErrors();

        foreach (CmsMedia::where('disk', 'public')->get() as $media) {
            $this->assertStringStartsWith('cms/', $media->path);
            $this->assertStringNotContainsString('..', $media->path);
            $this->assertStringNotContainsString('<', $media->original_name);
            Storage::disk('public')->assertExists($media->path);
        }
        $this->assertFileDoesNotExist(public_path('index.php.png'));
    }

    public function test_json_upload_for_the_picker_returns_the_new_image(): void
    {
        $this->actingAsAdminGuard();

        $this->postJson(route('admin.cms.media.store'), ['file' => UploadedFile::fake()->image('a.png', 200, 100)])
            ->assertCreated()->assertJsonStructure(['id', 'url', 'name', 'width', 'height']);

        $this->postJson(route('admin.cms.media.store'), ['file' => UploadedFile::fake()->createWithContent('a.png', '<?php')])
            ->assertStatus(422)->assertJsonValidationErrors('file');

        $this->getJson(route('admin.cms.media.picker'))->assertOk()->assertJsonStructure(['items' => [['id', 'url']], 'next']);
    }

    public function test_upload_requires_an_admin(): void
    {
        $this->post(route('admin.cms.media.store'), ['file' => UploadedFile::fake()->image('a.png')])->assertRedirect(route('admin.login'));
        $this->actingAsVendor();
        $this->post(route('admin.cms.media.store'), ['file' => UploadedFile::fake()->image('a.png')])->assertForbidden();

        $this->assertSame(0, CmsMedia::where('disk', 'public')->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    // ---------------------------------------------------------- deletion rules

    private function uploaded(): CmsMedia
    {
        $this->upload(UploadedFile::fake()->image('p.png', 200, 100))->assertSessionHasNoErrors();

        return CmsMedia::where('disk', 'public')->latest('id')->firstOrFail();
    }

    public function test_an_unused_image_can_be_deleted_and_its_file_is_removed(): void
    {
        $this->actingAsAdminGuard();
        $media = $this->uploaded();

        $this->delete(route('admin.cms.media.destroy', $media))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('cms_media', ['id' => $media->id]);
        Storage::disk('public')->assertMissing($media->path);
    }

    public function test_an_image_used_by_a_banner_cannot_be_deleted(): void
    {
        $this->actingAsAdminGuard();
        $media = $this->uploaded();
        $this->post(route('admin.cms.banners.store'), ['placement' => 'home_hero', 'title' => 'Promo', 'image_media_id' => $media->id, 'is_active' => 1])->assertSessionHasNoErrors();

        $this->delete(route('admin.cms.media.destroy', $media))->assertSessionHasErrors('file');

        $this->assertDatabaseHas('cms_media', ['id' => $media->id]);
        Storage::disk('public')->assertExists($media->path);
        $this->assertSame(1, CmsBanner::where('image_media_id', $media->id)->count());
    }

    public function test_an_image_used_by_a_section_draft_or_a_page_social_image_cannot_be_deleted(): void
    {
        $this->actingAsAdminGuard();

        $inSection = $this->uploaded();
        $this->put(route('admin.cms.site.section', ['home', 'why']), ['data' => ['image' => $inSection->id, 'image_alt' => 'x', 'eyebrow' => 'e', 'title' => 't', 'description' => 'd']])->assertSessionDoesntHaveErrors([], null, 'section_why');
        $this->delete(route('admin.cms.media.destroy', $inSection))->assertSessionHasErrors('file');

        $inPage = $this->uploaded();
        $page = CmsPage::where('slug', 'privacy')->firstOrFail();
        $this->put(route('admin.cms.pages.update', $page), ['title' => 'Privacy Policy', 'icon' => 'lock', 'body' => 'x', 'og_image_media_id' => $inPage->id, 'action' => 'save']);
        $this->delete(route('admin.cms.media.destroy', $inPage))->assertSessionHasErrors('file');

        // Once the reference is gone the image can be deleted.
        $this->put(route('admin.cms.pages.update', $page), ['title' => 'Privacy Policy', 'icon' => 'lock', 'body' => 'x', 'og_image_media_id' => '', 'action' => 'save']);
        $this->delete(route('admin.cms.media.destroy', $inPage))->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('cms_media', ['id' => $inPage->id]);
    }

    public function test_bundled_images_that_ship_with_the_site_cannot_be_deleted(): void
    {
        $this->actingAsAdminGuard();
        $bundled = CmsMedia::where('disk', 'bundled')->firstOrFail();

        $this->delete(route('admin.cms.media.destroy', $bundled))->assertSessionHasErrors('file');

        $this->assertDatabaseHas('cms_media', ['id' => $bundled->id]);
    }

    public function test_the_media_page_marks_usage_without_a_query_per_image(): void
    {
        $this->actingAsAdminGuard();
        for ($i = 0; $i < 5; $i++) {
            $this->uploaded();
        }

        \DB::enableQueryLog();
        $this->get(route('admin.cms.media.index'))->assertOk()->assertSee('In use');
        $queries = count(\DB::getQueryLog());
        \DB::disableQueryLog();

        $this->assertLessThan(30, $queries, "media library issued $queries queries");
    }

    public function test_the_library_is_paginated(): void
    {
        $this->actingAsAdminGuard();
        for ($i = 0; $i < 30; $i++) {
            CmsMedia::forceCreate(['disk' => 'public', 'path' => "cms/2026/01/f$i.png", 'filename' => "f$i.png", 'mime' => 'image/png', 'size' => 1, 'width' => 40, 'height' => 40]);
        }

        $response = $this->get(route('admin.cms.media.index'))->assertOk();
        $this->assertLessThanOrEqual(24, substr_count($response->getContent(), 'loading="lazy"'));
        $this->getJson(route('admin.cms.media.picker'))->assertJsonCount(18, 'items');
    }

    public function test_sections_reject_a_non_library_image_reference(): void
    {
        $this->actingAsAdminGuard();

        foreach (['https://evil.example/x.png', '../../etc/passwd', 'bundled:images/../../.env', '999999', '<script>'] as $bad) {
            $this->put(route('admin.cms.site.section', ['home', 'why']), ['data' => ['image' => $bad, 'image_alt' => 'x', 'eyebrow' => 'e', 'title' => 't', 'description' => 'd']])
                ->assertSessionHasErrorsIn('section_why');
        }
    }

    private function tmp(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cms');
        file_put_contents($path, $content);

        return $path;
    }
}
