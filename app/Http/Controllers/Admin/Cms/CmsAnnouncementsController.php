<?php

namespace App\Http\Controllers\Admin\Cms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Cms\CmsAnnouncementRequest;
use App\Models\Cms\CmsAnnouncement;
use App\Support\Cms\CmsAdmin;

/**
 * Informational notices only. Nothing here changes service availability,
 * payments or any other operational state.
 */
class CmsAnnouncementsController extends Controller
{
    public function index()
    {
        $announcements = CmsAnnouncement::orderByDesc('id')->paginate(20);
        $names = CmsAdmin::names($announcements->pluck('updated_by'));

        return view('admin.cms.announcements.index', compact('announcements', 'names'));
    }

    public function create()
    {
        return view('admin.cms.announcements.form', ['announcement' => new CmsAnnouncement(['audience' => 'public', 'type' => 'info', 'is_active' => true])]);
    }

    public function store(CmsAnnouncementRequest $request)
    {
        CmsAnnouncement::create($request->validated());

        return redirect()->route('admin.cms.announcements.index')->with('success', 'Announcement saved.');
    }

    public function edit(CmsAnnouncement $announcement)
    {
        return view('admin.cms.announcements.form', compact('announcement'));
    }

    public function update(CmsAnnouncementRequest $request, CmsAnnouncement $announcement)
    {
        $announcement->update($request->validated());

        return redirect()->route('admin.cms.announcements.index')->with('success', 'Announcement updated.');
    }

    public function destroy(CmsAnnouncement $announcement)
    {
        $announcement->delete();

        return redirect()->route('admin.cms.announcements.index')->with('success', 'Announcement deleted.');
    }
}
