<?php

namespace App\Http\Controllers;

use App\Dashboard\DashboardOverview;
use App\Dashboard\SetupChecklist;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardOverview $overview, SetupChecklist $checklist): View
    {
        $user = $request->user();

        return view('dashboard', $overview->for($user) + ['user' => $user, 'setup' => $checklist->for($user)]);
    }
}
