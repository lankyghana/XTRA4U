<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\UtilityBillIncident;

/**
 * Read-only admin view of grouped Utility Bills provider incidents and the
 * orders each one affected. Recovery of individual orders stays on the order
 * page (UtilityBillSalesController), which applies all the usual guards.
 */
class UtilityBillIncidentsController extends Controller
{
    public function index()
    {
        return view('admin.utility_bills.incidents', [
            'active' => UtilityBillIncident::query()->where('active_key', UtilityBillIncident::STATE_ACTIVE)->orderBy('first_detected_at')->get(),
            'recent' => UtilityBillIncident::query()->where('state', UtilityBillIncident::STATE_RECOVERED)->latest('recovered_at')->limit(30)->get(),
        ]);
    }

    public function show(UtilityBillIncident $incident)
    {
        return view('admin.utility_bills.incident', [
            'incident' => $incident,
            'orders' => $incident->orders()
                ->with(['order:id,payment_status', 'vendor:id,name'])
                ->orderBy('utility_bill_orders.id')
                ->paginate(30, ['utility_bill_orders.*']),
        ]);
    }
}
