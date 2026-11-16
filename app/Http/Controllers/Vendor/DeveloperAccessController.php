<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Models\Developer\DeveloperApplication;
use App\Models\Vendor;
use App\Services\DeveloperApi\DeveloperApplicationService;
use Illuminate\Support\Facades\Auth;

/**
 * A vendor decides who may integrate with ITS store through the API. Every query is
 * constrained to the authenticated vendor's id, so one vendor can never see or decide
 * another vendor's requests.
 */
class DeveloperAccessController extends Controller
{
    public function __construct(private readonly DeveloperApplicationService $apps) {}

    public function index()
    {
        return view('vendor.developer_access.index', [
            'applications' => DeveloperApplication::query()
                ->with('developer:id,name,company')
                ->where('vendor_id', $this->vendor()->id)
                ->latest('id')->get(),
        ]);
    }

    public function approve(int $application)
    {
        $this->apps->reviewVendorLink($this->owned($application), $this->vendor(), true);

        return back()->with('status', 'Access approved. This application can now see your store\'s catalog.');
    }

    public function reject(int $application)
    {
        $this->apps->reviewVendorLink($this->owned($application), $this->vendor(), false);

        return back()->with('status', 'Access declined.');
    }

    private function owned(int $id): DeveloperApplication
    {
        return DeveloperApplication::query()->where('vendor_id', $this->vendor()->id)->whereKey($id)->firstOrFail();
    }

    private function vendor(): Vendor
    {
        return Auth::guard('vendor')->user();
    }
}
