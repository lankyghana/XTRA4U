<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Developer\DeveloperApplication;
use App\Services\DeveloperApi\DeveloperApplicationService;
use App\Support\AdminAccess;
use Illuminate\Http\Request;

/**
 * Minimal, additive admin surface: review production-access requests. Reached only through
 * the existing `admin.only` (fail-closed AdminAccess) route group. The full developer
 * management section is Milestone 6.
 */
class DeveloperAccessController extends Controller
{
    public function __construct(private readonly DeveloperApplicationService $apps) {}

    public function index()
    {
        $with = ['developer:id,name,email,company', 'vendor:id,name,vendor_code'];

        return view('admin.developer_access.index', [
            'requested' => DeveloperApplication::query()->with($with)->where('production_status', DeveloperApplication::PRODUCTION_REQUESTED)->oldest('production_requested_at')->get(),
            'approved' => DeveloperApplication::query()->with($with)->where('production_status', DeveloperApplication::PRODUCTION_APPROVED)->latest('production_approved_at')->limit(50)->get(),
        ]);
    }

    public function approve(Request $request, DeveloperApplication $application)
    {
        $this->apps->reviewProduction($application, true, $this->actor(), $request->string('note')->toString() ?: null);

        return back()->with('status', 'Production access approved.');
    }

    public function reject(Request $request, DeveloperApplication $application)
    {
        $request->validate(['note' => ['required', 'string', 'max:500']]);
        $this->apps->reviewProduction($application, false, $this->actor(), $request->string('note')->toString());

        return back()->with('status', 'Production access rejected.');
    }

    public function withdraw(Request $request, DeveloperApplication $application)
    {
        $request->validate(['note' => ['required', 'string', 'max:500']]);
        $this->apps->withdrawProduction($application, $this->actor(), $request->string('note')->toString());

        return back()->with('status', 'Production access withdrawn. Production keys stopped working.');
    }

    private function actor(): string
    {
        $admin = AdminAccess::resolve();

        return ($admin instanceof \App\Models\Admin ? 'admin:' : 'user:').$admin?->getKey();
    }
}
