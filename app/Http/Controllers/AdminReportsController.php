<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\WalletTopup;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class AdminReportsController extends Controller
{
    public function index(Request $request)
    {
        $from = $this->parseDate($request->query('date_from'), now()->startOfMonth()->startOfDay());
        $to   = $this->parseDate($request->query('date_to'),   now()->endOfDay(), end: true);

        $vendorId = $request->filled('vendor_id') ? (int) $request->query('vendor_id') : null;

        // Base: paid orders in the selected window
        $base = Order::query()
            ->where('payment_status', 'paid')
            ->whereBetween('payment_completed_at', [$from, $to]);

        if ($vendorId) {
            $base->where('vendor_id', $vendorId);
        }

        // --- Sales Summary ---
        $totals = (clone $base)->selectRaw('
            COUNT(*) as order_count,
            COALESCE(SUM(amount_paid), 0) as total_revenue,
            COALESCE(SUM(platform_commission), 0) as platform_commission,
            COALESCE(SUM(owner_earning), 0) as owner_earning,
            COALESCE(SUM(reseller_earning), 0) as reseller_earning
        ')->first();

        $salesByDay = (clone $base)
            ->selectRaw('DATE(payment_completed_at) as date, COUNT(*) as order_count, COALESCE(SUM(amount_paid), 0) as revenue')
            ->groupByRaw('DATE(payment_completed_at)')
            ->orderBy('date')
            ->get();

        $topServices = (clone $base)
            ->selectRaw('service_purchased, COUNT(*) as order_count, COALESCE(SUM(amount_paid), 0) as revenue')
            ->groupBy('service_purchased')
            ->orderByDesc('revenue')
            ->limit(10)
            ->get();

        // --- Revenue Breakdown ---
        $byPaymentSource = (clone $base)
            ->selectRaw('COALESCE(payment_source, "other") as payment_source, COUNT(*) as order_count, COALESCE(SUM(amount_paid), 0) as revenue')
            ->groupBy('payment_source')
            ->orderByDesc('revenue')
            ->get();

        $topVendors = (clone $base)
            ->join('vendors', 'orders.vendor_id', '=', 'vendors.id')
            ->selectRaw('orders.vendor_id, vendors.name as vendor_name, vendors.vendor_code, COUNT(*) as order_count, COALESCE(SUM(orders.amount_paid), 0) as revenue')
            ->groupBy('orders.vendor_id', 'vendors.name', 'vendors.vendor_code')
            ->orderByDesc('revenue')
            ->limit(10)
            ->get();

        // --- Wallet Topup History ---
        $topupsBase = WalletTopup::query()->whereBetween('created_at', [$from, $to]);

        if ($vendorId) {
            $topupsBase->where('vendor_id', $vendorId);
        }

        $topupsByStatus = (clone $topupsBase)
            ->selectRaw('status, COUNT(*) as count, COALESCE(SUM(amount), 0) as total_amount, COALESCE(SUM(consumed), 0) as total_consumed')
            ->groupBy('status')
            ->orderByDesc('total_amount')
            ->get();

        $recentTopups = (clone $topupsBase)
            ->with('vendor:id,name,vendor_code')
            ->latest()
            ->limit(25)
            ->get();

        return view('admin.reports.index', [
            'totals'          => $totals,
            'salesByDay'      => $salesByDay,
            'topServices'     => $topServices,
            'byPaymentSource' => $byPaymentSource,
            'topVendors'      => $topVendors,
            'topupsByStatus'  => $topupsByStatus,
            'recentTopups'    => $recentTopups,
            'filters' => [
                'date_from' => $request->query('date_from', now()->startOfMonth()->toDateString()),
                'date_to'   => $request->query('date_to',   now()->toDateString()),
                'vendor_id' => $request->query('vendor_id'),
            ],
        ]);
    }

    private function parseDate(?string $value, $default, bool $end = false): CarbonImmutable
    {
        if (!$value) {
            return CarbonImmutable::instance($default);
        }

        try {
            $date = CarbonImmutable::parse($value);
            return $end ? $date->endOfDay() : $date->startOfDay();
        } catch (\Throwable) {
            return CarbonImmutable::instance($default);
        }
    }
}
