<?php

namespace App\Http\Controllers\Admin\Cms;

use App\Http\Controllers\Admin\Cms\Concerns\ReordersRows;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Cms\CmsBannerRequest;
use App\Models\Cms\CmsBanner;
use App\Support\Cms\CmsAdmin;
use Illuminate\Http\Request;

class CmsBannersController extends Controller
{
    use ReordersRows;

    public function index()
    {
        $banners = CmsBanner::with('image')->orderBy('placement')->orderBy('sort_order')->orderBy('id')->get();
        $names = CmsAdmin::names($banners->pluck('updated_by'));

        return view('admin.cms.banners.index', compact('banners', 'names'));
    }

    public function create()
    {
        return view('admin.cms.banners.form', ['banner' => new CmsBanner(['placement' => 'home_hero', 'is_active' => true])]);
    }

    public function store(CmsBannerRequest $request)
    {
        $banner = new CmsBanner($request->validated());
        $banner->sort_order = (int) CmsBanner::where('placement', $banner->placement)->max('sort_order') + 1;
        $banner->save();

        return redirect()->route('admin.cms.banners.index')->with('success', 'Banner saved.');
    }

    public function edit(CmsBanner $banner)
    {
        return view('admin.cms.banners.form', compact('banner'));
    }

    public function update(CmsBannerRequest $request, CmsBanner $banner)
    {
        $banner->update($request->validated());

        return redirect()->route('admin.cms.banners.index')->with('success', 'Banner updated.');
    }

    public function destroy(CmsBanner $banner)
    {
        $banner->delete();

        return redirect()->route('admin.cms.banners.index')->with('success', 'Banner deleted. The image stays in the media library.');
    }

    public function move(Request $request, CmsBanner $banner)
    {
        $group = CmsBanner::where('placement', $banner->placement)->orderBy('sort_order')->orderBy('id')->get();
        $this->reorderWithin($group, $banner, (string) $request->input('direction'));

        return back()->with('success', 'Order updated.');
    }
}
