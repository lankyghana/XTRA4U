<?php

namespace App\Http\Controllers\Developers;

use App\Services\DeveloperApi\DeveloperDashboardService;

class DashboardController extends DeveloperController
{
    public function __invoke(DeveloperDashboardService $dashboard)
    {
        return view('developers.dashboard', ['d' => $dashboard->forVendor($this->vendor())]);
    }
}
