<?php

namespace App\Http\Controllers\Admin\Cms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Cms\CmsNavigationRequest;
use App\Models\Cms\CmsNavigationItem;
use App\Support\Cms\CmsRegistry;
use Illuminate\Support\Facades\DB;

class CmsNavigationController extends Controller
{
    public function index()
    {
        $items = CmsNavigationItem::orderBy('sort_order')->orderBy('id')->get()->groupBy('location');

        $locations = [];
        foreach (CmsRegistry::NAV_LOCATIONS as $key => $label) {
            $locations[$key] = [
                'label' => $label,
                'items' => ($items[$key] ?? collect())->map(fn ($i) => ['label' => $i->label, 'url' => $i->url, 'visible' => (bool) $i->is_visible])->values()->all(),
            ];
        }

        return view('admin.cms.navigation.index', compact('locations'));
    }

    /** Replaces each location's list in one transaction, in the order submitted. */
    public function update(CmsNavigationRequest $request)
    {
        $data = $request->validated();

        DB::transaction(function () use ($data) {
            foreach (array_keys(CmsRegistry::NAV_LOCATIONS) as $location) {
                CmsNavigationItem::where('location', $location)->delete();
                foreach (array_values($data['items'][$location] ?? []) as $position => $row) {
                    $item = new CmsNavigationItem([
                        'location' => $location,
                        'label' => $row['label'],
                        'url' => $row['url'],
                        'sort_order' => $position,
                        'is_visible' => (bool) ($row['visible'] ?? false),
                    ]);
                    $item->save();
                }
            }
        });

        return redirect()->route('admin.cms.navigation.index')->with('success', 'Navigation saved.');
    }
}
