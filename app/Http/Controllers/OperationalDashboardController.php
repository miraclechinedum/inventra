<?php

namespace App\Http\Controllers;

use App\Dashboard\DashboardData;
use App\Reports\ReportFilters;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The detailed operational breakdown.
 *
 * This is the page `/dashboard` rendered before the headline dashboard took that route. It is kept
 * because it does things the new dashboard deliberately does not: period filtering, ledger
 * integrity detection, and the full alert and recent-activity breakdown across collections,
 * returns, refunds, expenses and purchases. Role scoping is unchanged — DashboardData still decides
 * what a Sales Representative may see.
 */
class OperationalDashboardController extends Controller
{
    public function __invoke(Request $request, DashboardData $dashboard): View
    {
        $user = $request->user();
        $filters = ReportFilters::fromRequest($request);

        return view('operations', $dashboard->for($user, $filters) + [
            'user' => $user,
            'filters' => $filters,
        ]);
    }
}
