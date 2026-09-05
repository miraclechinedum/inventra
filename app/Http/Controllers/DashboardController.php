<?php

namespace App\Http\Controllers;

use App\Dashboard\DashboardData;
use App\Reports\ReportFilters;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardData $dashboard): View
    {
        $user = $request->user();
        $filters = ReportFilters::fromRequest($request);

        return view('dashboard', $dashboard->for($user, $filters) + [
            'user' => $user,
            'filters' => $filters,
        ]);
    }
}
