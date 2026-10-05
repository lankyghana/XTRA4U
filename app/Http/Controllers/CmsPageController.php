<?php

namespace App\Http\Controllers;

use App\Support\Cms\CmsContent;

/**
 * Public, read-only entry points for CMS-managed pages. Only published content
 * is ever served here; drafts are reachable solely through the admin-protected
 * preview routes.
 */
class CmsPageController extends Controller
{
    public function about()
    {
        return view('pages.about');
    }

    /** /privacy and /terms keep their original URLs. */
    public function legal(CmsContent $cms, string $slug)
    {
        $page = $cms->page($slug);
        abort_if($page === null, 404);

        return view('pages.cms-page', [
            'page' => $page,
            'canonicalUrl' => url('/'.$slug),
            'preview' => false,
        ]);
    }

    /** /p/{slug}: any other published page. */
    public function show(CmsContent $cms, string $slug)
    {
        // System pages have their own canonical routes.
        $system = ['privacy' => 'privacy', 'terms' => 'terms', 'about' => 'about', 'home' => 'storefront.index'];
        if (isset($system[$slug])) {
            return redirect()->route($system[$slug], [], 301);
        }

        $page = $cms->page($slug);
        abort_if($page === null || $page['kind'] !== 'rich', 404);

        return view('pages.cms-page', [
            'page' => $page,
            'canonicalUrl' => route('cms.page', $slug),
            'preview' => false,
        ]);
    }

    public function faq(CmsContent $cms)
    {
        $faqs = $cms->faqs();
        abort_if($faqs === [], 404);

        return view('pages.faq', ['faqs' => $faqs]);
    }
}
