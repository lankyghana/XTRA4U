<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Models\UtilityBillOrder;
use App\Models\Vendor;
use App\Services\UtilityBills\FulfillmentStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Read-only Utility Bill sales for the authenticated vendor. Not a configuration
 * area: the service, billers and commission are admin-owned. Every query is
 * scoped to the vendor session by vendor_id (never a request parameter), and a
 * single order is looked up inside that scope, so another vendor's reference is
 * indistinguishable from a missing one (404).
 */
class UtilityBillSalesController extends Controller
{
    public function index(Request $request)
    {
        $vendor = $this->vendor();

        $base = UtilityBillOrder::query()->where('vendor_id', $vendor->id)->paidSales();

        $completed = (clone $base)->where('fulfillment_status', FulfillmentStatus::COMPLETED);

        $metrics = [
            // Sales value is NOT earnings: it is what customers paid for completed bills.
            'sales_value' => (string) ((clone $completed)->sum('bill_amount') ?: '0.00'),
            'successful' => (clone $completed)->count(),
            'processing' => (clone $base)->whereNotIn('fulfillment_status', FulfillmentStatus::TERMINAL)->count(),
            'failed' => (clone $base)->whereIn('fulfillment_status', [FulfillmentStatus::FAILED, FulfillmentStatus::PROVIDER_REFUNDED])->count(),
            // Earnings = credited commission only.
            'commission_earned' => (string) ((clone $base)->where('commission_status', UtilityBillOrder::COMMISSION_CREDITED)->sum('commission_amount') ?: '0.00'),
        ];

        $query = (clone $base)->latest('id');

        $status = (string) $request->input('status', '');
        if ($status === 'completed') {
            $query->where('fulfillment_status', FulfillmentStatus::COMPLETED);
        } elseif ($status === 'failed') {
            $query->whereIn('fulfillment_status', [FulfillmentStatus::FAILED, FulfillmentStatus::PROVIDER_REFUNDED]);
        } elseif ($status === 'processing') {
            $query->whereNotIn('fulfillment_status', FulfillmentStatus::TERMINAL);
        }

        if ($request->filled('q')) {
            $query->where('public_ref', 'like', '%'.trim((string) $request->input('q')).'%');
        }

        return view('vendor.utility_bills.index', [
            'vendor' => $vendor,
            'metrics' => $metrics,
            'sales' => $query->paginate(25)->withQueryString(),
            'selectedStatus' => $status,
            'searchTerm' => $request->input('q'),
        ]);
    }

    public function show(string $publicRef)
    {
        $vendor = $this->vendor();

        $sale = UtilityBillOrder::query()
            ->where('vendor_id', $vendor->id)
            ->paidSales()
            ->where('public_ref', $publicRef)
            ->firstOrFail();

        return view('vendor.utility_bills.show', ['vendor' => $vendor, 'sale' => $sale]);
    }

    private function vendor(): Vendor
    {
        $vendor = Auth::guard('vendor')->user();

        abort_unless($vendor instanceof Vendor, 403, 'Vendor account required.');

        return $vendor;
    }
}
