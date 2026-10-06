<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SupportCategory;
use App\Models\SupportQuickReply;
use App\Support\Support\SupportPrincipal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Admin CRUD for reusable replies. Wording is data, not code. */
class SupportQuickReplyController extends Controller
{
    public function index()
    {
        SupportPrincipal::admin();

        return view('admin.support.quick-replies', [
            'replies' => SupportQuickReply::with('category:id,name')->ordered()->get(),
            'categories' => SupportCategory::ordered()->get(['id', 'name']),
            'editing' => null,
        ]);
    }

    public function edit(SupportQuickReply $reply)
    {
        SupportPrincipal::admin();

        return view('admin.support.quick-replies', [
            'replies' => SupportQuickReply::with('category:id,name')->ordered()->get(),
            'categories' => SupportCategory::ordered()->get(['id', 'name']),
            'editing' => $reply,
        ]);
    }

    public function store(Request $request)
    {
        SupportPrincipal::admin();

        SupportQuickReply::create($this->validated($request) + [
            'sort_order' => ((int) SupportQuickReply::max('sort_order')) + 10,
        ]);

        return redirect()->route('admin.support.quick-replies.index')->with('status', 'Quick reply created.');
    }

    public function update(Request $request, SupportQuickReply $reply)
    {
        SupportPrincipal::admin();

        $reply->update($this->validated($request));

        return redirect()->route('admin.support.quick-replies.index')->with('status', 'Quick reply updated.');
    }

    public function toggle(SupportQuickReply $reply)
    {
        SupportPrincipal::admin();

        $reply->update(['is_active' => ! $reply->is_active]);

        return back()->with('status', $reply->is_active ? 'Quick reply activated.' : 'Quick reply deactivated.');
    }

    /** Swap sort position with the neighbouring reply. */
    public function move(Request $request, SupportQuickReply $reply)
    {
        SupportPrincipal::admin();
        $direction = $request->validate(['direction' => ['required', 'in:up,down']])['direction'];

        DB::transaction(function () use ($reply, $direction) {
            // Normalize to a dense sequence first so equal sort_orders cannot stall a move.
            $ids = SupportQuickReply::ordered()->lockForUpdate()->pluck('id')->all();
            $i = array_search($reply->id, $ids, true);
            $j = $direction === 'up' ? $i - 1 : $i + 1;

            if ($i !== false && isset($ids[$j])) {
                [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
            }
            foreach ($ids as $position => $id) {
                SupportQuickReply::whereKey($id)->update(['sort_order' => ($position + 1) * 10]);
            }
        });

        return back();
    }

    public function destroy(SupportQuickReply $reply)
    {
        SupportPrincipal::admin();

        $reply->delete();

        return back()->with('status', 'Quick reply deleted.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:2000'],
            'category_id' => ['nullable', 'integer', 'exists:support_categories,id'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}
