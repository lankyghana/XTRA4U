<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\VendorTierHistory;

class VendorTierHistoryController extends Controller
{
    public function index()
    {
        $histories = VendorTierHistory::with(['vendor', 'previousTier', 'newTier', 'admin'])
            ->latest()
            ->paginate(50);

        return view('admin.vendor-tier-history.index', compact('histories'));
    }
}
