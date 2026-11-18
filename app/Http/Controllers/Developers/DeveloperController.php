<?php

namespace App\Http\Controllers\Developers;

use App\Http\Controllers\Controller;
use App\Models\Developer\DeveloperApplication;
use App\Models\Vendor;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Shared helpers. The "developer" is simply the signed-in, approved vendor (existing `vendor` guard).
 * Applications are loaded ONLY through `vendor_id = <that vendor>`, never by id alone.
 */
abstract class DeveloperController extends Controller
{
    protected function vendor(): Vendor
    {
        /** @var Vendor $vendor */
        $vendor = Auth::guard('vendor')->user();

        return $vendor;
    }

    protected function actor(): string
    {
        return 'vendor:'.$this->vendor()->id;
    }

    /** 404 for anything not owned (never confirms that another vendor's application exists). */
    protected function application(int|string $id): DeveloperApplication
    {
        $app = DeveloperApplication::query()
            ->where('vendor_id', $this->vendor()->id)
            ->whereKey($id)
            ->firstOrFail();

        Gate::forUser($this->vendor())->authorize('manage', $app);

        return $app;
    }

    /** A response that carries a one-time secret: never cacheable. */
    protected function noStore($response)
    {
        return $response->withHeaders([
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
        ]);
    }
}
