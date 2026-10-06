<?php

namespace App\Http\Controllers\Admin\Cms;

use App\Http\Controllers\Admin\Cms\Concerns\ReordersRows;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Cms\CmsFaqRequest;
use App\Models\Cms\CmsFaq;
use Illuminate\Http\Request;

class CmsFaqsController extends Controller
{
    use ReordersRows;

    public function index()
    {
        $faqs = CmsFaq::orderBy('category')->orderBy('sort_order')->orderBy('id')->get()->groupBy('category');

        return view('admin.cms.faqs.index', compact('faqs'));
    }

    public function create()
    {
        return view('admin.cms.faqs.form', [
            'faq' => new CmsFaq(['category' => request('category', 'General'), 'is_active' => true]),
            'categories' => $this->categories(),
        ]);
    }

    public function store(CmsFaqRequest $request)
    {
        $faq = new CmsFaq($request->validated());
        $faq->sort_order = (int) CmsFaq::where('category', $faq->category)->max('sort_order') + 1;
        $faq->save();

        return redirect()->route('admin.cms.faqs.index')->with('success', 'FAQ saved.');
    }

    public function edit(CmsFaq $faq)
    {
        return view('admin.cms.faqs.form', ['faq' => $faq, 'categories' => $this->categories()]);
    }

    public function update(CmsFaqRequest $request, CmsFaq $faq)
    {
        $data = $request->validated();
        $moved = $data['category'] !== $faq->category;
        $faq->fill($data);
        if ($moved) {
            $faq->sort_order = (int) CmsFaq::where('category', $data['category'])->max('sort_order') + 1;
        }
        $faq->save();

        return redirect()->route('admin.cms.faqs.index')->with('success', 'FAQ updated.');
    }

    /** Deleting is permanent; deactivate (untick Active) to hide a question but keep it. */
    public function destroy(CmsFaq $faq)
    {
        $faq->delete();

        return redirect()->route('admin.cms.faqs.index')->with('success', 'FAQ deleted.');
    }

    public function move(Request $request, CmsFaq $faq)
    {
        $group = CmsFaq::where('category', $faq->category)->orderBy('sort_order')->orderBy('id')->get();
        $this->reorderWithin($group, $faq, (string) $request->input('direction'));

        return back()->with('success', 'Order updated.');
    }

    /** @return list<string> */
    private function categories(): array
    {
        return CmsFaq::distinct()->orderBy('category')->pluck('category')->all();
    }
}
