<?php

namespace App\Http\Controllers;

use App\Models\NetworkService;

class AdminResultCheckerDashboardController extends Controller
{
    public function index()
    {
        $services = NetworkService::resultsChecker()
            ->orderBy('name')
            ->withCount([
                'pins as total_pins',
                'pins as used_pins' => fn ($q) => $q->where('status', 'sold'),
                'pins as available_pins' => fn ($q) => $q->where('status', 'available'),
            ])
            ->get();

        $recentOrders = \App\Models\ResultCheckerOrder::with(['service', 'vendor'])
            ->latest()
            ->take(10)
            ->get();

        return view('admin.result_checkers.dashboard', [
            'services' => $services,
            'recentOrders' => $recentOrders,
        ]);
    }
}
