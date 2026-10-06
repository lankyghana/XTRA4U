<?php

namespace App\Http\Controllers\Admin\Cms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Cms\CmsPageRequest;
use App\Models\Cms\CmsPage;
use App\Models\Cms\CmsRevision;
use App\Support\Cms\CmsAdmin;
use App\Support\Cms\CmsContent;
use App\Support\Cms\CmsPageService;
use App\Support\Cms\CmsRegistry;
use App\Support\MainStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Field-by-field editing of the homepage and About page. Admins never touch
 * HTML: each section exposes the structured fields declared in CmsRegistry.
 */
class CmsStructuredPageController extends Controller
{
    public function __construct(private readonly CmsPageService $service) {}

    public function edit(string $key)
    {
        $page = $this->page($key);
        $defs = CmsRegistry::sections($key);
        $rows = $page->sections()->get()->keyBy('key');

        $sections = [];
        foreach ($defs as $sectionKey => $def) {
            $row = $rows[$sectionKey] ?? null;
            $stored = $row?->draft_data ?? $row?->data ?? [];
            $values = [];
            foreach ($def['fields'] as $name => $field) {
                $values[$name] = array_key_exists($name, $stored)
                    ? $stored[$name]
                    : ($field['type'] === 'repeater' ? ($field['default'] ?? []) : ($field['default'] ?? ''));
            }
            $sections[$sectionKey] = [
                'def' => $def,
                'values' => $values,
                'visible' => $row ? (bool) $row->is_visible : true,
                'has_draft' => (bool) $row?->hasDraft(),
                'order' => $row ? $row->sort_order : count($sections),
            ];
        }
        uasort($sections, fn ($a, $b) => $a['order'] <=> $b['order']);

        $pending = $page->hasDraft() || collect($sections)->contains('has_draft', true);
        $seo = [];
        foreach (['seo_title', 'meta_description', 'canonical_url', 'og_title', 'og_description', 'og_image_media_id', 'robots_noindex'] as $field) {
            $seo[$field] = $page->editorValue($field);
        }

        return view('admin.cms.structured.edit', [
            'key' => $key,
            'page' => $page,
            'sections' => $sections,
            'pending' => $pending,
            'seo' => $seo,
            'defaultSeo' => CmsRegistry::structuredSeo($key),
            'publisher' => $page->published_by ? (CmsAdmin::names([$page->published_by])[$page->published_by] ?? null) : null,
        ]);
    }

    public function saveSection(Request $request, string $key, string $section)
    {
        $page = $this->page($key);
        $this->knownSection($key, $section);

        try {
            $this->service->saveSection($page, $section, (array) $request->input('data', []));
        } catch (ValidationException $e) {
            // Several section forms share one page: keep each one's errors in its own bag.
            throw $e->errorBag('section_'.$section)->redirectTo(route('admin.cms.site.edit', $key).'#section-'.$section);
        }

        return redirect(route('admin.cms.site.edit', $key).'#section-'.$section)
            ->with('success', 'Section saved as a draft. Publish to make it live.');
    }

    public function visibility(Request $request, string $key, string $section)
    {
        $page = $this->page($key);
        $this->knownSection($key, $section);
        $this->service->setVisibility($page, $section, $request->boolean('visible'));

        return redirect(route('admin.cms.site.edit', $key).'#section-'.$section)
            ->with('success', $request->boolean('visible') ? 'Section is now shown on the site.' : 'Section is now hidden from the site.');
    }

    public function move(Request $request, string $key, string $section)
    {
        $page = $this->page($key);
        $this->knownSection($key, $section);
        $this->service->move($page, $section, (string) $request->input('direction'));

        return redirect(route('admin.cms.site.edit', $key).'#section-'.$section)->with('success', 'Section order updated.');
    }

    public function saveSeo(Request $request, string $key)
    {
        $page = $this->page($key);
        $data = Validator::make($this->normaliseSeo($request), CmsPageRequest::seoRules())->validate();
        $this->service->saveStructuredSeo($page, $data);

        return redirect(route('admin.cms.site.edit', $key).'#seo')->with('success', 'Search and social settings saved as a draft.');
    }

    public function publish(string $key)
    {
        $this->service->publish($this->page($key));

        return back()->with('success', 'Published. The public page now shows these changes.');
    }

    public function discard(string $key)
    {
        $this->service->discardDraft($this->page($key));

        return back()->with('success', 'All pending changes were discarded.');
    }

    /** Admin-only render of the pending draft. Never cached, never indexed. */
    public function preview(string $key, CmsContent $cms)
    {
        $this->page($key);
        $cms->usePreview();

        $response = $key === 'home'
            ? response()->view('storefront.index', ['mainStore' => MainStore::vendor()])
            : response()->view('pages.about');

        return $response
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function history(string $key)
    {
        $page = $this->page($key);
        $revisions = $page->revisions()->paginate(15);

        return view('admin.cms.history', [
            'page' => $page,
            'revisions' => $revisions,
            'names' => CmsAdmin::names($revisions->pluck('created_by')),
            'restoreRoute' => fn ($rev) => route('admin.cms.site.restore', [$key, $rev]),
            'backUrl' => route('admin.cms.site.edit', $key),
        ]);
    }

    public function restore(string $key, CmsRevision $revision)
    {
        $this->service->restoreRevision($this->page($key), $revision);

        return redirect()->route('admin.cms.site.edit', $key)->with('success', 'Revision loaded as pending changes. Review it, then publish.');
    }

    private function page(string $key): CmsPage
    {
        abort_unless(isset(CmsRegistry::structuredPages()[$key]), 404);

        return CmsPage::whereIn('kind', ['home', 'about'])->where('slug', $key)->firstOrFail();
    }

    private function knownSection(string $key, string $section): void
    {
        abort_unless(CmsRegistry::section($key, $section) !== null, 404);
    }

    private function normaliseSeo(Request $request): array
    {
        $in = $request->only(['seo_title', 'meta_description', 'canonical_url', 'og_title', 'og_description', 'og_image_media_id']);
        foreach ($in as $k => $v) {
            $in[$k] = trim((string) $v) === '' ? null : trim((string) $v);
        }
        $in['robots_noindex'] = $request->boolean('robots_noindex');

        return $in;
    }
}
