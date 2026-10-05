<?php

namespace App\Support\Cms;

use App\Models\Cms\CmsBanner;
use App\Models\Cms\CmsMedia;
use App\Models\Cms\CmsPage;
use App\Models\Cms\CmsSection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Hardened image intake for the CMS.
 *
 * The client's file name, extension and Content-Type are never trusted. A file
 * is accepted only if (1) PHP's fileinfo sniffs an allowed raster MIME from the
 * bytes, (2) getimagesize agrees, (3) the dimensions are inside the limits,
 * and (4) GD can fully decode it. It is then RE-ENCODED to a new file, which
 * discards EXIF, appended payloads and polyglot tricks, and is stored under a
 * server-generated name. SVG and everything else is refused.
 */
class MediaService
{
    private const EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function store(UploadedFile $file, ?string $altText = null): CmsMedia
    {
        $cfg = config('cms.media');

        if (! $file->isValid()) {
            $this->fail('The upload failed. Please try again.');
        }
        if ($file->getSize() > $cfg['max_kb'] * 1024) {
            $this->fail('The image is larger than '.round($cfg['max_kb'] / 1024, 1).' MB.');
        }

        $path = $file->getRealPath();
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: '';
        if (! in_array($mime, $cfg['allowed_mimes'], true)) {
            $this->fail('Only JPEG, PNG or WebP images are allowed.');
        }

        $info = @getimagesize($path);
        if ($info === false || ($info['mime'] ?? null) !== $mime) {
            $this->fail('That file is not a valid image.');
        }

        [$width, $height] = $info;
        if ($width < $cfg['min_dimension'] || $height < $cfg['min_dimension']) {
            $this->fail('The image is too small (minimum '.$cfg['min_dimension'].' px).');
        }
        if ($width > $cfg['max_dimension'] || $height > $cfg['max_dimension'] || ($width * $height) > $cfg['max_megapixels'] * 1_000_000) {
            $this->fail('The image is too large (maximum '.$cfg['max_dimension'].' px, '.$cfg['max_megapixels'].' megapixels).');
        }

        $bytes = $this->reencode((string) file_get_contents($path), $mime);

        $ext = self::EXTENSIONS[$mime];
        $filename = Str::lower((string) Str::ulid()).'.'.$ext;
        $relative = trim($cfg['directory'], '/').'/'.now()->format('Y/m').'/'.$filename;

        Storage::disk(CmsMedia::DISK_UPLOAD)->put($relative, $bytes);

        $media = new CmsMedia(['alt_text' => $altText !== null ? Str::limit(trim($altText), 255, '') : null]);
        $media->forceFill([
            'disk' => CmsMedia::DISK_UPLOAD,
            'path' => $relative,
            'filename' => $filename,
            'original_name' => Str::limit(preg_replace('/[^\w.\- ]+/u', '_', $file->getClientOriginalName()), 200, ''),
            'mime' => $mime,
            'size' => strlen($bytes),
            'width' => $width,
            'height' => $height,
        ])->save();

        return $media;
    }

    /** Decode with GD and write fresh bytes. Anything GD cannot decode is rejected. */
    private function reencode(string $raw, string $mime): string
    {
        $image = @imagecreatefromstring($raw);
        if ($image === false) {
            $this->fail('That image could not be processed. It may be corrupt.');
        }

        try {
            ob_start();
            $ok = match ($mime) {
                'image/jpeg' => imagejpeg($image, null, 85),
                'image/png' => (function () use ($image) {
                    imagealphablending($image, false);
                    imagesavealpha($image, true);

                    return imagepng($image, null, 6);
                })(),
                'image/webp' => function_exists('imagewebp') && imagewebp($image, null, 85),
                default => false,
            };
            $bytes = (string) ob_get_clean();
        } finally {
            imagedestroy($image);
        }

        if (! $ok || $bytes === '') {
            $this->fail('That image could not be processed on this server.');
        }

        return $bytes;
    }

    /**
     * Human-readable places that still reference this file. Empty = safe to delete.
     *
     * @return list<string>
     */
    public function usage(CmsMedia $media): array
    {
        $id = $media->id;
        $used = [];

        foreach (CmsBanner::where('image_media_id', $id)->get(['id', 'title']) as $banner) {
            $used[] = 'Banner: '.$banner->title;
        }

        foreach (CmsPage::withTrashed()->get() as $page) {
            $live = (int) $page->og_image_media_id === $id;
            $draft = $page->hasDraft() && (int) ($page->draft['og_image_media_id'] ?? 0) === $id;
            if ($live || $draft) {
                $used[] = 'Social image of page: '.$page->title;
            }
        }

        foreach (CmsSection::with('page:id,slug,title')->get() as $section) {
            $defs = CmsRegistry::section($section->page?->slug ?? '', $section->key);
            if (! $defs) {
                continue;
            }
            foreach ($defs['fields'] as $name => $field) {
                if ($field['type'] !== 'image') {
                    continue;
                }
                foreach ([$section->data, $section->draft_data] as $payload) {
                    if (is_array($payload) && (int) ($payload[$name] ?? 0) === $id) {
                        $used[] = $section->page->title.' / '.$defs['label'];

                        continue 3;
                    }
                }
            }
        }

        return array_values(array_unique($used));
    }

    /**
     * Ids of every media row referenced anywhere, in a handful of queries, so a
     * library listing can show "in use" without one lookup per image.
     *
     * @return array<int, true>
     */
    public function usedIds(): array
    {
        $ids = [];

        foreach (CmsBanner::pluck('image_media_id') as $id) {
            $ids[(int) $id] = true;
        }

        foreach (CmsPage::withTrashed()->get(['og_image_media_id', 'draft']) as $page) {
            if ($page->og_image_media_id) {
                $ids[(int) $page->og_image_media_id] = true;
            }
            if ($page->hasDraft() && ! empty($page->draft['og_image_media_id'])) {
                $ids[(int) $page->draft['og_image_media_id']] = true;
            }
        }

        foreach (CmsSection::with('page:id,slug')->get(['id', 'page_id', 'key', 'data', 'draft_data']) as $section) {
            $def = CmsRegistry::section($section->page?->slug ?? '', $section->key);
            foreach ($def['fields'] ?? [] as $name => $field) {
                if ($field['type'] !== 'image') {
                    continue;
                }
                foreach ([$section->data, $section->draft_data] as $payload) {
                    if (is_array($payload) && is_numeric($payload[$name] ?? null)) {
                        $ids[(int) $payload[$name]] = true;
                    }
                }
            }
        }

        return $ids;
    }

    /**
     * @throws ValidationException when bundled, or still in use
     */
    public function delete(CmsMedia $media): void
    {
        if ($media->isBundled()) {
            $this->fail('This image ships with the site and cannot be deleted.');
        }

        $usage = $this->usage($media);
        if ($usage !== []) {
            $this->fail('This image is still in use: '.implode('; ', $usage).'. Replace it there first.');
        }

        // Path is server-generated at upload; still refuse anything that is not inside the CMS directory.
        $dir = trim(config('cms.media.directory'), '/').'/';
        if (str_starts_with($media->path, $dir) && ! str_contains($media->path, '..')) {
            Storage::disk(CmsMedia::DISK_UPLOAD)->delete($media->path);
        }

        $media->delete();
    }

    /** @throws ValidationException */
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
