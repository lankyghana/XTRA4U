<?php

namespace App\Support\Cms;

use App\Models\Cms\CmsPage;
use App\Models\Cms\CmsRevision;
use App\Models\Cms\CmsSection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * All state changes of pages and structured sections: draft edits, publish,
 * unpublish, revision restore, archive. Controllers stay thin and every
 * status / actor / publish field is written here, from the session, never
 * from request input.
 *
 * Model: editing never touches what visitors see. Edits are stored as a
 * pending draft (page `draft` JSON, section `draft_data`); Publish promotes
 * the draft to the live columns and appends a revision.
 */
class CmsPageService
{
    /** Live fields + (structured pages) live section content: what a revision stores. */
    public static function snapshot(CmsPage $page): array
    {
        $snap = ['fields' => []];
        foreach (CmsPage::EDITABLE as $field) {
            $snap['fields'][$field] = $page->{$field};
        }

        if ($page->isStructured()) {
            $snap['sections'] = $page->sections()->get()->mapWithKeys(fn (CmsSection $s) => [
                $s->key => ['data' => $s->data, 'is_visible' => $s->is_visible, 'sort_order' => $s->sort_order],
            ])->all();
        }

        return $snap;
    }

    // ---------------------------------------------------------- rich pages

    /** @param array<string,mixed> $data validated page fields incl. slug */
    public function create(array $data): CmsPage
    {
        $page = new CmsPage(array_intersect_key($data, array_flip(array_merge(['slug'], CmsPage::EDITABLE))));
        $page->forceFill([
            'kind' => 'rich',
            'status' => CmsPage::STATUS_DRAFT,
            'is_system' => false,
        ])->save();

        // A brand-new page lives entirely in its draft until first published.
        $page->forceFill(['draft' => $this->draftFields($data)])->save();

        return $page;
    }

    /** Save edits as a pending draft. Live content is untouched. */
    public function saveDraft(CmsPage $page, array $data): CmsPage
    {
        $page->forceFill(['draft' => $this->draftFields($data)])->save();

        // Title is also kept live on never-published pages so admin lists show it.
        if (! $page->isPublished() && isset($data['title'])) {
            $page->forceFill(['title' => $data['title']])->save();
        }

        return $page;
    }

    public function discardDraft(CmsPage $page): void
    {
        $page->forceFill(['draft' => null])->save();

        if ($page->isStructured()) {
            $page->sections()->update(['draft_data' => null]);
            CmsCache::forgetPage($page->slug);
        }
    }

    /** Slug can change on non-system pages only; callers validate uniqueness. */
    public function changeSlug(CmsPage $page, string $slug): void
    {
        if ($page->is_system || $page->slug === $slug) {
            return;
        }
        $page->forceFill(['slug' => $slug])->save();
    }

    public function publish(CmsPage $page): CmsPage
    {
        return DB::transaction(function () use ($page) {
            $page = CmsPage::whereKey($page->id)->lockForUpdate()->firstOrFail();

            $overlay = $page->hasDraft() ? $page->draft : [];
            foreach (CmsPage::EDITABLE as $field) {
                if (array_key_exists($field, $overlay)) {
                    $page->{$field} = $overlay[$field];
                }
            }

            if ($page->isStructured()) {
                foreach ($page->sections()->get() as $section) {
                    if ($section->draft_data !== null) {
                        $section->data = $section->draft_data;
                        $section->draft_data = null;
                        $section->save();
                    }
                }
            }

            $page->forceFill([
                'draft' => null,
                'status' => CmsPage::STATUS_PUBLISHED,
                'published_at' => now(),
                'published_by' => CmsAdmin::actor(),
            ])->save();

            $this->recordRevision($page, 'published');

            return $page;
        });
    }

    /** System pages (legal, home, about) cannot be taken offline from the CMS. */
    public function unpublish(CmsPage $page): CmsPage
    {
        if ($page->is_system) {
            throw ValidationException::withMessages(['page' => 'This page is part of the core site and cannot be unpublished. Archive or edit a copy instead.']);
        }

        $page->forceFill(['status' => CmsPage::STATUS_DRAFT])->save();
        $this->recordRevision($page, 'unpublished');

        return $page;
    }

    public function archive(CmsPage $page): void
    {
        if ($page->is_system) {
            throw ValidationException::withMessages(['page' => 'Core pages cannot be archived.']);
        }
        $page->forceFill(['status' => CmsPage::STATUS_DRAFT])->save();
        $page->delete();
    }

    public function unarchive(CmsPage $page): void
    {
        $page->restore();
    }

    /** Load a past revision into the pending draft; nothing goes live until Publish. */
    public function restoreRevision(CmsPage $page, CmsRevision $revision): void
    {
        abort_unless($revision->page_id === $page->id, 404);

        $snap = $revision->snapshot;
        $fields = array_intersect_key($snap['fields'] ?? [], array_flip(CmsPage::EDITABLE));
        $page->forceFill(['draft' => $fields])->save();

        if ($page->isStructured()) {
            foreach ($snap['sections'] ?? [] as $key => $row) {
                $section = $page->sections()->where('key', $key)->first();
                if ($section) {
                    $section->draft_data = $row['data'] ?? null;
                    $section->save();
                }
            }
        }
    }

    private function recordRevision(CmsPage $page, string $action): void
    {
        $next = ((int) $page->revisions()->max('version')) + 1;

        $revision = new CmsRevision;
        $revision->forceFill([
            'page_id' => $page->id,
            'version' => $next,
            'action' => $action,
            'snapshot' => self::snapshot($page->fresh()),
            'created_by' => CmsAdmin::actor(),
            'created_by_name' => CmsAdmin::actorName(),
            'created_at' => now(),
        ])->save();
    }

    /** Only schema-listed editable fields are ever written to the draft. */
    private function draftFields(array $data): array
    {
        return array_intersect_key($data, array_flip(CmsPage::EDITABLE));
    }

    // ---------------------------------------------------- structured pages

    /** Save one section's content to its draft. */
    public function saveSection(CmsPage $page, string $key, array $input): CmsSection
    {
        $def = CmsRegistry::section($page->slug, $key);
        abort_unless($page->isStructured() && $def, 404);

        $clean = CmsSectionInput::validate($def['fields'], $input);
        CmsSectionInput::assertMinimums($def['fields'], $clean);

        $section = $this->sectionRow($page, $key);
        $section->draft_data = $clean;
        $section->save();

        return $section;
    }

    public function setVisibility(CmsPage $page, string $key, bool $visible): CmsSection
    {
        $def = CmsRegistry::section($page->slug, $key);
        abort_unless($page->isStructured() && $def && $def['hideable'], 404);

        $section = $this->sectionRow($page, $key);
        $section->is_visible = $visible;
        $section->save();

        return $section;
    }

    /** Swap a movable section with its movable neighbour. */
    public function move(CmsPage $page, string $key, string $direction): void
    {
        $defs = CmsRegistry::sections($page->slug);
        abort_unless(isset($defs[$key]) && $defs[$key]['movable'] && in_array($direction, ['up', 'down'], true), 404);

        DB::transaction(function () use ($page, $key, $direction, $defs) {
            // Make sure every section has a row so sort_order is total.
            $position = 0;
            foreach ($defs as $k => $_) {
                $row = $this->sectionRow($page, $k);
                if ($row->wasRecentlyCreated) {
                    $row->sort_order = $position;
                    $row->save();
                }
                $position++;
            }

            $movable = $page->sections()->get()
                ->filter(fn ($s) => $defs[$s->key]['movable'] ?? false)
                ->sortBy('sort_order')->values();

            $index = $movable->search(fn ($s) => $s->key === $key);
            $swapWith = $direction === 'up' ? $index - 1 : $index + 1;
            if ($index === false || ! isset($movable[$swapWith])) {
                return;
            }

            $a = $movable[$index];
            $b = $movable[$swapWith];
            [$orderA, $orderB] = [$a->sort_order, $b->sort_order];
            $a->sort_order = $orderB;
            $b->sort_order = $orderA;
            $a->save();
            $b->save();
        });
    }

    private function sectionRow(CmsPage $page, string $key): CmsSection
    {
        $row = CmsSection::firstOrNew(['page_id' => $page->id, 'key' => $key]);
        if (! $row->exists) {
            $row->data = CmsRegistry::defaults($page->slug, $key);
            $row->is_visible = true;
            $row->sort_order = array_search($key, array_keys(CmsRegistry::sections($page->slug)), true) ?: 0;
            $row->save();
            $row->wasRecentlyCreated = true;
        }

        return $row;
    }

    /** Edit the page-level SEO draft of a structured page. */
    public function saveStructuredSeo(CmsPage $page, array $seo): void
    {
        abort_unless($page->isStructured(), 404);
        $page->forceFill(['draft' => array_intersect_key(array_merge($page->draft ?? [], $seo), array_flip(CmsPage::EDITABLE))])->save();
    }
}
