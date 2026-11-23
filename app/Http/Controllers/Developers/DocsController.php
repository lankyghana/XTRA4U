<?php

namespace App\Http\Controllers\Developers;

use App\Http\Controllers\Controller;
use App\Services\DeveloperApi\Docs\PortalDocs;

/**
 * API documentation inside the Developer Portal. Behind the same `developer.access` gate as the rest of the portal
 * (guests -> vendor login, unapproved -> approval-required page); the pages are static files and contain nothing
 * vendor-specific.
 */
class DocsController extends Controller
{
    public function index(PortalDocs $docs)
    {
        return view('developers.docs.index', ['pages' => $docs->pages()]);
    }

    public function show(PortalDocs $docs, string $page)
    {
        $found = $docs->page($page);

        abort_if($found === null, 404);

        return view('developers.docs.show', ['doc' => $found, 'pages' => $docs->pages()]);
    }
}
