<?php

namespace App\Support\Cms;

use App\Models\Cms\CmsAnnouncement;
use App\Models\Cms\CmsBanner;
use App\Models\Cms\CmsFaq;
use App\Models\Cms\CmsMedia;
use App\Models\Cms\CmsNavigationItem;
use App\Models\Cms\CmsPage;
use App\Models\Cms\CmsSection;
use App\Models\Cms\CmsSetting;
use Illuminate\Support\HtmlString;

/**
 * Read side of the CMS used by the public Blade views (shared as `$cms`).
 *
 * Fallback rule: when the one-time backfill has NOT run (marker absent) the
 * registry defaults - the former hardcoded content - are used, so a half-
 * deployed environment still renders the existing site. Once the backfill has
 * run, the database is the truth: an admin who deletes every banner gets none,
 * not the defaults back. Query/connection errors are never swallowed.
 *
 * In preview mode (admin-only route) draft data is shown instead of live data
 * and nothing is cached.
 */
class CmsContent
{
    public const SEEDED_KEY = 'system.seeded';

    private bool $preview = false;

    /** @var array<int, string|null> */
    private array $mediaUrls = [];

    private ?bool $seeded = null;

    public function usePreview(bool $on = true): static
    {
        $this->preview = $on;

        return $this;
    }

    /**
     * Drop all per-request state (preview flag, memoised lookups). Called when a
     * request finishes so nothing can leak into the next one on a long-lived
     * worker (Octane, queue workers, tests).
     */
    public function flush(): void
    {
        $this->preview = false;
        $this->mediaUrls = [];
        $this->seeded = null;
    }

    public function previewing(): bool
    {
        return $this->preview;
    }

    // ---------------------------------------------------------------- seeded

    public function seeded(): bool
    {
        return $this->seeded ??= array_key_exists(self::SEEDED_KEY, $this->rawSettings());
    }

    // -------------------------------------------------------------- settings

    /** @return array<string, string|null> */
    private function rawSettings(): array
    {
        return CmsCache::remember(CmsCache::SETTINGS, fn () => CmsSetting::pluck('value', 'key')->all());
    }

    public function setting(string $key, ?string $fallback = null): ?string
    {
        $all = $this->rawSettings();
        $value = $all[$key] ?? null;

        if ($value === null || $value === '') {
            // An explicitly cleared optional value stays cleared once seeded.
            if ($this->seeded() && array_key_exists($key, $all)) {
                return $fallback;
            }

            return CmsRegistry::settings()[$key]['default'] ?? $fallback;
        }

        return $value;
    }

    /** Social links that are configured and pass link validation. @return list<array{key:string,label:string,url:string}> */
    public function socialLinks(): array
    {
        $out = [];
        foreach (CmsRegistry::SOCIAL as $key => $label) {
            $url = CmsLink::safe($this->setting("social.$key"));
            if ($url && CmsLink::isExternal($url)) {
                $out[] = ['key' => $key, 'label' => $label, 'url' => $url];
            }
        }

        return $out;
    }

    // ------------------------------------------------------------ navigation

    /** @return list<array{label:string,url:string}> */
    public function nav(string $location, ?string $shopUrl = null): array
    {
        $all = CmsCache::remember(CmsCache::NAV, fn () => CmsNavigationItem::where('is_visible', true)
            ->orderBy('sort_order')->orderBy('id')
            ->get(['location', 'label', 'url'])
            ->groupBy('location')
            ->map(fn ($rows) => $rows->map(fn ($r) => ['label' => $r->label, 'url' => $r->url])->all())
            ->all());

        $rows = $all[$location] ?? null;
        if ($rows === null && ! $this->seeded()) {
            $rows = array_map(fn ($pair) => ['label' => $pair[0], 'url' => $pair[1]], CmsRegistry::navigationDefaults()[$location] ?? []);
        }

        $out = [];
        foreach ($rows ?? [] as $row) {
            $url = CmsLink::safe($row['url'], $shopUrl);
            if ($url !== null) {
                $out[] = ['label' => $row['label'], 'url' => $url];
            }
        }

        return $out;
    }

    // --------------------------------------------------------------- banners

    /** Slides currently inside their date window. @return list<array{title:string,url:?string,image:string}> */
    public function banners(string $placement): array
    {
        $rows = CmsCache::remember(CmsCache::BANNERS, function () {
            return CmsBanner::with('image')->where('is_active', true)
                ->orderBy('sort_order')->orderBy('id')->get()
                ->map(fn ($b) => [
                    'placement' => $b->placement,
                    'title' => $b->title,
                    'link' => $b->link_url,
                    'image' => $b->image?->url(),
                    'starts_at' => $b->starts_at?->timestamp,
                    'ends_at' => $b->ends_at?->timestamp,
                ])->all();
        });

        if ($rows === [] && ! $this->seeded() && $placement === 'home_hero') {
            return array_map(fn ($d) => ['title' => $d[1], 'url' => null, 'image' => asset($d[0])], CmsRegistry::defaultBanners());
        }

        $now = now()->timestamp;
        $out = [];
        foreach ($rows as $r) {
            if ($r['placement'] !== $placement || ! $r['image']) {
                continue;
            }
            if (($r['starts_at'] && $r['starts_at'] > $now) || ($r['ends_at'] && $r['ends_at'] <= $now)) {
                continue;
            }
            $out[] = ['title' => $r['title'], 'url' => CmsLink::safe($r['link']), 'image' => $r['image']];
        }

        return $out;
    }

    // --------------------------------------------------------- announcements

    /** @return list<array{id:int,type:string,title:string,message:?string,url:?string,link_text:?string}> */
    public function announcements(string $audience): array
    {
        $rows = CmsCache::remember(CmsCache::ANNOUNCEMENTS, fn () => CmsAnnouncement::where('is_active', true)
            ->orderByDesc('id')->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'audience' => $a->audience,
                'type' => $a->type,
                'title' => $a->title,
                'message' => $a->message,
                'link' => $a->link_url,
                'link_text' => $a->link_text,
                'starts_at' => $a->starts_at?->timestamp,
                'ends_at' => $a->ends_at?->timestamp,
            ])->all());

        $now = now()->timestamp;
        $out = [];
        foreach ($rows as $r) {
            if ($r['audience'] !== $audience) {
                continue;
            }
            if (($r['starts_at'] && $r['starts_at'] > $now) || ($r['ends_at'] && $r['ends_at'] <= $now)) {
                continue;
            }
            $out[] = [
                'id' => $r['id'],
                'type' => $r['type'],
                'title' => $r['title'],
                'message' => $r['message'],
                'url' => CmsLink::safe($r['link']),
                'link_text' => $r['link_text'],
            ];
        }

        return $out;
    }

    // ------------------------------------------------------------------ FAQs

    /** @return array<string, list<array{question:string,answer:string}>> grouped by category, in order */
    public function faqs(): array
    {
        $rows = CmsCache::remember(CmsCache::FAQS, fn () => CmsFaq::where('is_active', true)
            ->orderBy('category')->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn ($f) => ['category' => $f->category, 'question' => $f->question, 'answer' => CmsMarkdown::toHtml($f->answer)])
            ->all());

        $grouped = [];
        foreach ($rows as $r) {
            $grouped[$r['category']][] = ['question' => $r['question'], 'answer' => $r['answer']];
        }

        return $grouped;
    }

    public function hasFaqs(): bool
    {
        return $this->faqs() !== [];
    }

    // -------------------------------------------------------- structured pages

    /**
     * Sections of a structured page in display order, merged over defaults.
     *
     * @return array<string, array{key:string, visible:bool, data:array, def:array}>
     */
    public function sections(string $pageKey): array
    {
        $build = function () use ($pageKey) {
            $rows = [];
            $page = CmsPage::where('slug', $pageKey)->first();
            if ($page) {
                $rows = CmsSection::where('page_id', $page->id)->get()->keyBy('key')->all();
            }

            $defs = CmsRegistry::sections($pageKey);
            $out = [];
            $position = 0;
            foreach ($defs as $key => $def) {
                $row = $rows[$key] ?? null;
                $out[$key] = [
                    'key' => $key,
                    'visible' => $row ? (bool) $row->is_visible : true,
                    'order' => $row ? (int) $row->sort_order : $position,
                    'live' => $row?->data,
                    'draft' => $row?->draft_data,
                ];
                $position++;
            }

            return $out;
        };

        $raw = $this->preview ? $build() : CmsCache::remember(CmsCache::sectionsKey($pageKey), $build);

        $defs = CmsRegistry::sections($pageKey);
        $out = [];
        foreach ($raw as $key => $row) {
            $payload = $this->preview ? ($row['draft'] ?? $row['live']) : $row['live'];
            $out[$key] = [
                'key' => $key,
                'visible' => $row['visible'],
                'order' => $row['order'],
                'def' => $defs[$key],
                'data' => $this->mergeData($defs[$key]['fields'], $payload ?? []),
            ];
        }

        uasort($out, fn ($a, $b) => $a['order'] <=> $b['order']);

        $this->primeImages($out);

        return $out;
    }

    public function section(string $pageKey, string $key): array
    {
        return $this->sections($pageKey)[$key]['data'] ?? CmsRegistry::defaults($pageKey, $key);
    }

    /** Is a section switched on? Non-hideable sections are always on. */
    public function sectionVisible(string $pageKey, string $key): bool
    {
        $sections = $this->sections($pageKey);

        return ! isset($sections[$key]) || $sections[$key]['visible'] || ! $sections[$key]['def']['hideable'];
    }

    /** Movable sections in display order (those the homepage loop renders). @return list<string> */
    public function movableOrder(string $pageKey): array
    {
        $keys = [];
        foreach ($this->sections($pageKey) as $key => $s) {
            if ($s['def']['movable'] && ($s['visible'] || ! $s['def']['hideable'])) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /** Missing keys fall back to defaults; repeaters are taken as stored. */
    private function mergeData(array $fields, array $stored): array
    {
        $out = [];
        foreach ($fields as $name => $field) {
            $out[$name] = array_key_exists($name, $stored)
                ? $stored[$name]
                : ($field['type'] === 'repeater' ? ($field['default'] ?? []) : ($field['default'] ?? ''));
        }

        return $out;
    }

    /** One query for every image a page's sections reference. */
    private function primeImages(array $sections): void
    {
        $ids = [];
        foreach ($sections as $s) {
            foreach ($s['def']['fields'] as $name => $field) {
                if ($field['type'] === 'image') {
                    $ref = $s['data'][$name] ?? null;
                    if (is_numeric($ref) && ! array_key_exists((int) $ref, $this->mediaUrls)) {
                        $ids[] = (int) $ref;
                    }
                }
            }
        }
        if ($ids) {
            $found = CmsMedia::whereIn('id', array_unique($ids))->get()->keyBy('id');
            foreach (array_unique($ids) as $id) {
                $this->mediaUrls[$id] = isset($found[$id]) ? $found[$id]->url() : null;
            }
        }
    }

    public function image(int|string|null $ref): ?string
    {
        if (is_numeric($ref)) {
            $id = (int) $ref;

            return $this->mediaUrls[$id] ??= CmsMedia::find($id)?->url();
        }

        return CmsMedia::urlFor($ref);
    }

    // ------------------------------------------------------------ rich pages

    /**
     * Published payload for a rich page, or null when absent / unpublished.
     * Preview mode returns the draft overlay of any non-archived page.
     */
    public function page(string $slug): ?array
    {
        if ($this->preview) {
            $page = CmsPage::where('slug', $slug)->first();

            return $page ? $this->payload($page, true) : null;
        }

        $payload = CmsCache::remember(CmsCache::pageKey($slug), function () use ($slug) {
            $page = CmsPage::published()->where('slug', $slug)->first();

            return $page ? $this->payload($page, false) : ['missing' => true];
        });

        if (isset($payload['missing'])) {
            return $this->seeded() ? null : $this->registryPage($slug);
        }

        return $payload;
    }

    /** The former hardcoded legal copy, used only before the backfill has run. */
    private function registryPage(string $slug): ?array
    {
        $def = CmsRegistry::systemRichPages()[$slug] ?? null;
        if (! $def) {
            return null;
        }

        $page = new CmsPage(array_intersect_key($def, array_flip(['title', 'icon', 'body', 'seo_title', 'meta_description'])) + ['slug' => $slug]);
        $page->kind = 'rich';
        $page->published_at = now();

        return $this->payload($page, false);
    }

    public function payload(CmsPage $page, bool $useDraft): array
    {
        $get = fn (string $f) => $useDraft ? $page->editorValue($f) : $page->{$f};
        $body = (string) $get('body');
        $ogImageId = $get('og_image_media_id');

        return [
            'id' => $page->id,
            'slug' => $page->slug,
            'kind' => $page->kind,
            'title' => (string) $get('title'),
            'icon' => $get('icon') ?: 'document',
            'html' => CmsMarkdown::toHtml($body),
            'cards' => CmsMarkdown::cards(CmsMarkdown::toHtml($body)),
            'seo' => [
                'title' => $get('seo_title') ?: (string) $get('title'),
                'description' => $get('meta_description') ?: CmsMarkdown::excerpt($body),
                'canonical' => $get('canonical_url') ?: null,
                'og_title' => $get('og_title') ?: ($get('seo_title') ?: (string) $get('title')),
                'og_description' => $get('og_description') ?: ($get('meta_description') ?: CmsMarkdown::excerpt($body)),
                'og_image' => $ogImageId ? $this->image((int) $ogImageId) : null,
                'noindex' => (bool) $get('robots_noindex'),
            ],
            'published_at' => $page->published_at?->timestamp,
            'updated_at' => $page->updated_at?->timestamp,
        ];
    }

    /** SEO block for a structured page (home/about). */
    public function structuredSeo(string $slug): array
    {
        $defaults = CmsRegistry::structuredSeo($slug);
        $payload = $this->page($slug);

        if ($payload === null) {
            return [
                'title' => $defaults['seo_title'] ?? 'XTRA4U',
                'description' => $defaults['meta_description'] ?? '',
                'canonical' => null,
                'og_title' => $defaults['seo_title'] ?? 'XTRA4U',
                'og_description' => $defaults['meta_description'] ?? '',
                'og_image' => null,
                'noindex' => false,
            ];
        }

        return $payload['seo'];
    }

    // -------------------------------------------------------------- presenters

    /** Escape, then turn [[words]] into the highlighted span. */
    public function accent(?string $text, string $color = 'var(--x4-violet)'): HtmlString
    {
        $escaped = e((string) $text);
        $html = preg_replace(
            '/\[\[(.+?)\]\]/u',
            '<span style="color: '.$color.';">$1</span>',
            $escaped
        );

        return new HtmlString($html);
    }

    /** Safe link for a view, resolving {shop}. */
    public function link(?string $url, ?string $shopUrl = null): ?string
    {
        return CmsLink::safe($url, $shopUrl);
    }
}
