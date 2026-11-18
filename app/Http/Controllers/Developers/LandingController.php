<?php

namespace App\Http\Controllers\Developers;

use App\Http\Controllers\Controller;
use App\Models\Vendor;
use App\Services\DeveloperApi\PublicCatalogService;
use App\Services\DeveloperApi\VendorApiAccess;
use Illuminate\Support\Facades\Auth;

/**
 * Public information page. Anyone can read it; nothing on it is vendor- or customer-specific.
 * Only the call-to-action changes with who is looking (guest / vendor awaiting approval / approved vendor).
 */
class LandingController extends Controller
{
    public function __invoke(PublicCatalogService $catalog)
    {
        $vendor = Auth::guard('vendor')->user();
        $vendor = $vendor instanceof Vendor ? $vendor : null;

        return view('developers.landing', [
            'services' => $catalog->services(),
            'state' => match (true) {
                $vendor === null => 'guest',
                VendorApiAccess::isEligible($vendor) => 'approved',
                default => 'pending',
            },
        ]);
    }
}
