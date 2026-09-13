<?php

namespace App\Http\Controllers;

use App\Actions\Sale\CorrectSale;
use App\Http\Requests\Sale\CorrectSaleRequest;
use App\Models\Customer;
use App\Models\Sale;
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

    public function store(CorrectSaleRequest $request, Sale $sale, CorrectSale $action): RedirectResponse
    {
        $correction = $action->execute($request->user(), $sale, $request->validated());

        return redirect()->route('sales.show', $sale)->with(
            'status',
            'Sale corrected. The receipt now shows the corrected figures; correction '.$correction->id.' records what changed.',
        );
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
