<?php

namespace App\Http\Controllers\Admin\Cms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Cms\CmsPageRequest;
use App\Models\Cms\CmsPage;
use App\Models\Cms\CmsRevision;
use App\Support\Cms\CmsAdmin;
use App\Support\Cms\CmsContent;
use App\Support\Cms\CmsMarkdown;
use App\Support\Cms\CmsPageService;
use Illuminate\Http\Request;

/** Rich-text pages: Privacy, Terms and any page an admin adds. */
class CmsPagesController extends Controller
{
    public function __construct(private readonly CmsPageService $service) {}

    public function index(Request $request)
    {
        $tab = in_array($request->query('status'), ['published', 'draft', 'archived'], true) ? $request->query('status') : 'all';
        $q = trim((string) $request->query('q', ''));

        $query = CmsPage::where('kind', 'rich');
        if ($tab === 'archived') {
            $query->onlyTrashed();
        } elseif ($tab === 'published') {
            $query->published();
        } elseif ($tab === 'draft') {
            $query->where('status', CmsPage::STATUS_DRAFT);
        }
        if ($q !== '') {
            $like = '%'.addcslashes($q, '\\%_').'%';
            $query->where(fn ($w) => $w->where('title', 'like', $like)->orWhere('slug', 'like', $like));
        }

        $pages = $query->orderByDesc('updated_at')->paginate(20)->withQueryString();
        $names = CmsAdmin::names($pages->pluck('updated_by'));

        $counts = [
            'all' => CmsPage::where('kind', 'rich')->count(),
            'published' => CmsPage::where('kind', 'rich')->published()->count(),
            'draft' => CmsPage::where('kind', 'rich')->where('status', CmsPage::STATUS_DRAFT)->count(),
            'archived' => CmsPage::where('kind', 'rich')->onlyTrashed()->count(),
        ];

        return view('admin.cms.pages.index', compact('pages', 'tab', 'q', 'names', 'counts'));
    }

    public function create()
    {
        return view('admin.cms.pages.form', ['page' => new CmsPage(['icon' => 'document'])]);
    }

    public function store(CmsPageRequest $request)
    {
        $page = $this->service->create($request->validated());

        if ($request->input('action') === 'publish') {
            $this->service->publish($page);

            return redirect()->route('admin.cms.pages.edit', $page)->with('success', 'Page published.');
        }

        return redirect()->route('admin.cms.pages.edit', $page)->with('success', 'Draft saved. It is not visible to visitors until you publish it.');
    }

    public function edit(CmsPage $page)
    {
        $this->ensureRich($page);

        return view('admin.cms.pages.form', ['page' => $page]);
    }

    public function update(CmsPageRequest $request, CmsPage $page)
    {
        $this->ensureRich($page);

        $data = $request->validated();
        if (isset($data['slug'])) {
            $this->service->changeSlug($page, $data['slug']);
        }
        $this->service->saveDraft($page, $data);

        if ($request->input('action') === 'publish') {
            $this->service->publish($page);

            return redirect()->route('admin.cms.pages.edit', $page)->with('success', 'Changes published.');
        }

        return redirect()->route('admin.cms.pages.edit', $page)->with('success', 'Draft saved.'.($page->isPublished() ? ' The live page is unchanged until you publish.' : ''));
    }

    public function publish(CmsPage $page)
    {
        $this->ensureRich($page);
        $this->service->publish($page);

        return back()->with('success', 'Page published.');
    }

    public function unpublish(CmsPage $page)
    {
        $this->ensureRich($page);
        $this->service->unpublish($page);

        return back()->with('success', 'Page unpublished. Visitors will now see a 404 for it.');
    }

    public function discard(CmsPage $page)
    {
        $this->ensureRich($page);
        $this->service->discardDraft($page);

        return back()->with('success', 'Pending changes discarded.');
    }

    /** Admin-only render of the draft (or live content if there is no draft). */
    public function preview(CmsPage $page, CmsContent $cms)
    {
        $this->ensureRich($page);

        $cms->usePreview();
        $payload = $cms->payload($page, true);

        return response()
            ->view('pages.cms-page', ['page' => $payload, 'canonicalUrl' => null, 'preview' => true])
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function history(CmsPage $page)
    {
        $this->ensureRich($page);
        $revisions = $page->revisions()->paginate(15);
        $names = CmsAdmin::names($revisions->pluck('created_by'));

        return view('admin.cms.history', [
            'page' => $page, 'revisions' => $revisions, 'names' => $names,
            'restoreRoute' => fn ($rev) => route('admin.cms.pages.restore', [$page, $rev]),
            'backUrl' => route('admin.cms.pages.edit', $page),
        ]);
    }

    public function restore(CmsPage $page, CmsRevision $revision)
    {
        $this->ensureRich($page);
        $this->service->restoreRevision($page, $revision);

        return redirect()->route('admin.cms.pages.edit', $page)->with('success', 'Revision loaded into the draft. Review it, then publish.');
    }

    public function archive(CmsPage $page)
    {
        $this->ensureRich($page);
        $this->service->archive($page);

        return redirect()->route('admin.cms.pages.index')->with('success', 'Page archived. It can be restored from the Archived tab.');
    }

    public function unarchive(int $id)
    {
        $page = CmsPage::onlyTrashed()->where('kind', 'rich')->findOrFail($id);
        $this->service->unarchive($page);

        return redirect()->route('admin.cms.pages.edit', $page)->with('success', 'Page restored as a draft.');
    }

    /** Server-side rendering for the editor's Preview tab; same sanitizer as the public site. */
    public function markdownPreview(Request $request)
    {
        $data = $request->validate(['markdown' => ['nullable', 'string', 'max:100000']]);

        return response()->json(['html' => CmsMarkdown::toHtml($data['markdown'] ?? '')]);
    }

    private function ensureRich(CmsPage $page): void
    {
        abort_unless($page->kind === 'rich', 404);
    }
}
