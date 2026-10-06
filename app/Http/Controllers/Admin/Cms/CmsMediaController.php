<?php

namespace App\Http\Controllers\Admin\Cms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Cms\CmsMediaUploadRequest;
use App\Models\Cms\CmsMedia;
use App\Support\Cms\CmsAdmin;
use App\Support\Cms\MediaService;
use Illuminate\Http\Request;

class CmsMediaController extends Controller
{
    public function __construct(private readonly MediaService $media) {}

    /** Paginated grid. Usage is computed once for the whole library, not per image. */
    public function index()
    {
        $items = CmsMedia::orderByDesc('id')->paginate(24);
        $used = $this->media->usedIds();
        $names = CmsAdmin::names($items->pluck('created_by'));

        return view('admin.cms.media.index', compact('items', 'used', 'names'));
    }

    /** JSON feed for the image picker modal. */
    public function picker(Request $request)
    {
        $page = CmsMedia::orderByDesc('id')->paginate(18);

        return response()->json([
            'items' => $page->getCollection()->map(fn (CmsMedia $m) => [
                'id' => $m->id,
                'url' => $m->url(),
                'name' => $m->original_name,
                'alt' => $m->alt_text,
                'width' => $m->width,
                'height' => $m->height,
            ])->values(),
            'next' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
        ]);
    }

    public function store(CmsMediaUploadRequest $request)
    {
        $media = $this->media->store($request->file('file'), $request->input('alt_text'));

        if ($request->expectsJson()) {
            return response()->json([
                'id' => $media->id,
                'url' => $media->url(),
                'name' => $media->original_name,
                'alt' => $media->alt_text,
                'width' => $media->width,
                'height' => $media->height,
            ], 201);
        }

        return redirect()->route('admin.cms.media.index')->with('success', 'Image uploaded.');
    }

    public function update(Request $request, CmsMedia $media)
    {
        $data = $request->validate(['alt_text' => ['nullable', 'string', 'max:255']]);
        $media->update(['alt_text' => $data['alt_text'] ?? null]);

        return back()->with('success', 'Description saved.');
    }

    public function destroy(CmsMedia $media)
    {
        $this->media->delete($media);

        return back()->with('success', 'Image deleted.');
    }
}
