<?php

namespace App\Support\Cms;

use App\Models\Cms\CmsBanner;
use App\Models\Cms\CmsMedia;
use App\Models\Cms\CmsNavigationItem;
use App\Models\Cms\CmsPage;
use App\Models\Cms\CmsRevision;
use App\Models\Cms\CmsSection;
use App\Models\Cms\CmsSetting;

/**
 * One-time, idempotent backfill: copies the content that used to be hardcoded
 * in the Blade views into the CMS tables, so the live site looks the same the
 * moment the views start reading from the CMS. Run from a migration (the
 * deployment only runs `migrate`). After it completes it writes a marker; from
 * then on the database - not the registry defaults - is authoritative.
 */
final class CmsDefaults
{
    public static function install(): void
    {
        if (CmsSetting::where('key', CmsContent::SEEDED_KEY)->exists()) {
            return;
        }

        $media = self::bundledMedia();

        foreach (CmsRegistry::settings() as $key => $def) {
            CmsSetting::firstOrCreate(['key' => $key], ['value' => $def['default']]);
        }

        foreach (CmsRegistry::structuredPages() as $key => $meta) {
            $page = self::page($key, $key, $meta['title'], CmsRegistry::structuredSeo($key));
            $position = 0;
            foreach (CmsRegistry::sections($key) as $sectionKey => $def) {
                $data = CmsRegistry::fieldDefaults($def['fields']);
                foreach ($def['fields'] as $name => $field) {
                    if ($field['type'] === 'image' && is_string($data[$name]) && isset($media[$data[$name]])) {
                        $data[$name] = $media[$data[$name]];
                    }
                }

                $section = CmsSection::firstOrNew(['page_id' => $page->id, 'key' => $sectionKey]);
                if (! $section->exists) {
                    $section->data = $data;
                    $section->is_visible = true;
                    $section->sort_order = $position;
                    $section->save();
                }
                $position++;
            }
            self::revision($page);
        }

        foreach (CmsRegistry::systemRichPages() as $slug => $def) {
            $page = self::page($slug, 'rich', $def['title'], $def, $def['body'], $def['icon']);
            self::revision($page);
        }

        foreach (CmsRegistry::navigationDefaults() as $location => $items) {
            if (CmsNavigationItem::where('location', $location)->exists()) {
                continue;
            }
            foreach ($items as $i => [$label, $url]) {
                $item = new CmsNavigationItem(['location' => $location, 'label' => $label, 'url' => $url, 'sort_order' => $i, 'is_visible' => true]);
                $item->save();
            }
        }

        if (! CmsBanner::exists()) {
            foreach (CmsRegistry::defaultBanners() as $i => [$path, $title]) {
                $mediaId = $media[CmsRegistry::BUNDLED_PREFIX.$path] ?? null;
                if ($mediaId) {
                    $banner = new CmsBanner(['placement' => 'home_hero', 'title' => $title, 'link_url' => null, 'image_media_id' => $mediaId, 'is_active' => true, 'sort_order' => $i]);
                    $banner->save();
                }
            }
        }

        // Marker last: a failed run is retried from the top, and every step above is idempotent.
        $marker = new CmsSetting(['key' => CmsContent::SEEDED_KEY, 'value' => now()->toIso8601String()]);
        $marker->save();

        CmsCache::flushAll();
    }

    /** Register every shipped image the registry references; returns ['bundled:path' => id]. */
    private static function bundledMedia(): array
    {
        $paths = [];
        foreach (CmsRegistry::defaultBanners() as [$path]) {
            $paths[$path] = null;
        }
        foreach (array_keys(CmsRegistry::structuredPages()) as $page) {
            foreach (CmsRegistry::sections($page) as $def) {
                foreach ($def['fields'] as $field) {
                    if ($field['type'] === 'image' && str_starts_with((string) $field['default'], CmsRegistry::BUNDLED_PREFIX)) {
                        $paths[substr($field['default'], strlen(CmsRegistry::BUNDLED_PREFIX))] = null;
                    }
                }
            }
        }

        $map = [];
        foreach (array_keys($paths) as $path) {
            $existing = CmsMedia::where('path', $path)->first();
            if (! $existing) {
                $file = public_path($path);
                $size = is_file($file) ? @getimagesize($file) : false;
                $existing = new CmsMedia;
                $existing->forceFill([
                    'disk' => CmsMedia::DISK_BUNDLED,
                    'path' => $path,
                    'filename' => basename($path),
                    'original_name' => basename($path),
                    'mime' => $size['mime'] ?? 'image/jpeg',
                    'size' => is_file($file) ? (int) filesize($file) : 0,
                    'width' => $size[0] ?? null,
                    'height' => $size[1] ?? null,
                    'alt_text' => null,
                ])->save();
            }
            $map[CmsRegistry::BUNDLED_PREFIX.$path] = $existing->id;
        }

        return $map;
    }

    private static function page(string $slug, string $kind, string $title, array $seo, ?string $body = null, ?string $icon = null): CmsPage
    {
        $page = CmsPage::withTrashed()->where('slug', $slug)->first();
        if ($page) {
            return $page;
        }

        $page = new CmsPage([
            'slug' => $slug,
            'title' => $title,
            'icon' => $icon,
            'body' => $body,
            'seo_title' => $seo['seo_title'] ?? null,
            'meta_description' => $seo['meta_description'] ?? null,
        ]);
        $page->forceFill([
            'kind' => $kind,
            'status' => CmsPage::STATUS_PUBLISHED,
            'is_system' => true,
            'published_at' => now(),
            'published_by' => 'system',
        ])->save();

        return $page;
    }

    private static function revision(CmsPage $page): void
    {
        if ($page->revisions()->exists()) {
            return;
        }

        $revision = new CmsRevision;
        $revision->forceFill([
            'page_id' => $page->id,
            'version' => 1,
            'action' => 'published',
            'snapshot' => CmsPageService::snapshot($page),
            'created_by' => 'system',
            'created_by_name' => 'Initial import of the previous site content',
            'created_at' => now(),
        ])->save();
    }
}
