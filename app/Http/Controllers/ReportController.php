<?php

namespace App\Http\Controllers;

use App\Models\Report;
use App\Reports\BusinessReports;
use App\Reports\ReportFilters;
use App\Reports\ReportsDashboard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ReportController extends Controller
{
    /**
     * The Reports dashboard.
     *
     * Behind the same `viewAny` policy as every detail report — the figures here are the same
     * financial data those pages show, gathered onto one screen, so the same rule governs both.
     *
     * The whole dataset for all three periods is computed server-side in one pass and handed to the
     * view. Switching Week/Month/Year is then a matter of showing what is already there: no second
     * request, and no arithmetic in the browser.
     */
    public function index(ReportsDashboard $dashboard): View
    {
        $this->authorizeReports();

        return view('reports.index', ['dashboard' => $dashboard->all()]);
    }

    public function sales(Request $request, BusinessReports $reports): View
    {
        return $this->render($request, fn ($filters) => $reports->sales($filters));
    }

    public function collections(Request $request, BusinessReports $reports): View
    {
        return $this->render($request, fn ($filters) => $reports->collections($filters));
    }

    public function receivables(Request $request, BusinessReports $reports): View
    {
        return $this->render($request, fn ($filters) => $reports->receivables($filters));
    }

    public function expenses(Request $request, BusinessReports $reports): View
    {
        return $this->render($request, fn ($filters) => $reports->expenses($filters));
    }

    public function purchases(Request $request, BusinessReports $reports): View
    {
        return $this->render($request, fn ($filters) => $reports->purchases($filters));
    }

    public function inventory(Request $request, BusinessReports $reports): View
    {
        return $this->render($request, fn ($filters) => $reports->inventory($filters));
    }

    public function products(Request $request, BusinessReports $reports): View
    {
        return $this->render($request, fn ($filters) => $reports->products($filters));
    }

    public function customers(Request $request, BusinessReports $reports): View
    {
        return $this->render($request, fn ($filters) => $reports->customers($filters));
    }

    public function staff(Request $request, BusinessReports $reports): View
    {
        return $this->render($request, fn ($filters) => $reports->staff($filters));
    }

    public function summary(Request $request, BusinessReports $reports): View
    {
        return $this->render($request, fn ($filters) => $reports->summary($filters));
    }

    private function render(Request $request, callable $report): View
    {
        $this->authorizeReports();

        return view('reports.show', $report(ReportFilters::fromRequest($request)));
    }

    private function authorizeReports(): void
    {
        Gate::authorize('viewAny', Report::class);
    }
}
