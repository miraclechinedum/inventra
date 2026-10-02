<?php

namespace App\Http\Controllers;

use App\Actions\Sale\CorrectSale;
use App\Http\Requests\Sale\CorrectSaleRequest;
use App\Models\Customer;
use App\Models\Sale;
use App\Support\Money;
use App\Support\SaleCorrectionEligibility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SaleCorrectionController extends Controller
{
    public function create(Request $request, Sale $sale): View
    {
        Gate::authorize('correct', $sale);

        // Eligibility is checked here as well as in the action so the user is told why a Sale
        // cannot be corrected instead of meeting a validation error after filling the form in.
        if (($blocked = SaleCorrectionEligibility::blockedReason($sale)) !== null) {
            throw ValidationException::withMessages(['sale' => $blocked]);
        }

        $sale->load(['items', 'customer:id,customer_code,first_name,last_name']);

        return view('sales.corrections.create', [
            'sale' => $sale,
            'canChangeCustomer' => SaleCorrectionEligibility::permitsCustomerChange($sale),
            'customers' => SaleCorrectionEligibility::permitsCustomerChange($sale)
                ? Customer::query()->where('is_active', true)->orderBy('first_name')->get(['id', 'customer_code', 'first_name', 'last_name'])
                : collect(),
        ]);
    }

    /**
     * The correction form as a fragment, for the Sales list's side panel.
     *
     * The same policy, the same eligibility check and the same fields as the full page — it is the
     * identical Blade partial, so the two cannot drift. Only the wrapper differs: the page renders
     * it inside a layout, the panel renders it inside a slide-over, and both post to `store`.
     */
    public function panel(Request $request, Sale $sale): View
    {
        Gate::authorize('correct', $sale);

        if (($blocked = SaleCorrectionEligibility::blockedReason($sale)) !== null) {
            throw ValidationException::withMessages(['sale' => $blocked]);
        }

        $sale->load(['items', 'customer:id,customer_code,first_name,last_name']);

        return view('sales.corrections._form', [
            'sale' => $sale,
            'inPanel' => true,
            'canChangeCustomer' => SaleCorrectionEligibility::permitsCustomerChange($sale),
            'customers' => SaleCorrectionEligibility::permitsCustomerChange($sale)
                ? Customer::query()->where('is_active', true)->orderBy('first_name')->get(['id', 'customer_code', 'first_name', 'last_name'])
                : collect(),
        ]);
    }

    public function store(CorrectSaleRequest $request, Sale $sale, CorrectSale $action): RedirectResponse
    {
        $correction = $action->execute($request->user(), $sale, $request->validated());
        $sale->refresh();

        // The before/after figures come from the correction record the action just wrote, not from
        // anything recomputed here, so the message cannot claim a change the ledger does not show.
        $movedTotal = bccomp((string) $correction->total_before, (string) $correction->total_after, 2) !== 0;

        $message = $movedTotal
            ? 'Sale updated — '.$sale->customer_name_snapshot.': ₦'
                .Money::format((string) $correction->total_before).' → ₦'
                .Money::format((string) $correction->total_after).'.'
            : 'Sale updated successfully — '.$sale->sale_number.'.';

        // Both destinations show the same banner. A correction started from the Sales list returns
        // there, so the operator keeps their place; one started from the Sale page goes back to the
        // Sale. The value is a fixed keyword, never a URL, so it cannot be used to redirect
        // anywhere the application did not choose.
        return $request->input('return_to') === 'index'
            ? redirect()->route('sales.index')->with('saleCorrected', $message)
            : redirect()->route('sales.show', $sale)->with('saleCorrected', $message);
    }

    public function show(Sale $sale): View
    {
        Gate::authorize('viewAudit', $sale);

        return view('sales.corrections.index', [
            'sale' => $sale,
            'corrections' => $sale->corrections()->with(['corrector:id,name', 'addedItems', 'supersededItems'])
                ->orderByDesc('id')->get(),
        ]);
    }
}
