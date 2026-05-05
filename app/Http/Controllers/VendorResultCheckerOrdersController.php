<?php

namespace App\Http\Controllers;

use App\Models\ResultCheckerOrder;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class VendorResultCheckerOrdersController extends Controller
{
    public function index(Request $request)
    {
        $vendor = $this->resolveVendor();

        $ordersQuery = ResultCheckerOrder::query()
            ->where('vendor_id', $vendor->id)
            ->with('service')
            ->latest();

        if ($request->filled('status')) {
            $ordersQuery->where('status', $request->input('status'));
        }

        if ($request->filled('q')) {
            $q = trim((string) $request->input('q'));
            $ordersQuery->where(function ($query) use ($q) {
                $query->where('customer_phone', 'like', "%{$q}%")
                    ->orWhere('payment_reference', 'like', "%{$q}%");

                if (is_numeric($q)) {
                    $query->orWhere('id', (int) $q);
                }
            });
        }

        $orders = $ordersQuery->paginate(50)->withQueryString();

        return view('vendor.result_checkers.orders', [
            'vendor' => $vendor,
            'orders' => $orders,
            'selectedStatus' => $request->input('status'),
            'searchTerm' => $request->input('q'),
        ]);
    }

    private function resolveVendor(): Vendor
    {
        $vendorGuardUser = Auth::guard('vendor')->user();

        if (! $vendorGuardUser instanceof Vendor) {
            abort(403, 'Vendor account required.');
        }

        return $vendorGuardUser;
    }
}
