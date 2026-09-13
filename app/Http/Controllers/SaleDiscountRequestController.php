<?php

namespace App\Http\Controllers;

use App\Actions\Sale\DecideSaleDiscount;
use App\Actions\Sale\RequestSaleDiscount;
use App\Enums\DiscountRequestStatus;
use App\Http\Requests\Sale\DecideSaleDiscountRequest;
use App\Http\Requests\Sale\StoreSaleDiscountRequest;
use App\Models\Sale;
use App\Models\SaleDiscountRequest;
use App\Support\PerPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SaleDiscountRequestController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', SaleDiscountRequest::class);
        $statusInput = $request->query('status');
        $statuses = array_column(DiscountRequestStatus::cases(), 'value');

        $requests = SaleDiscountRequest::query()
            ->with(['sale:id,sale_number,customer_name_snapshot,total_amount', 'requester:id,name', 'decider:id,name'])
            ->when(is_string($statusInput) && in_array($statusInput, $statuses, true),
                fn ($query) => $query->where('status', $statusInput))
            ->orderByRaw("status = '".DiscountRequestStatus::Pending->value."' desc")
            ->latest('id')
            ->paginate(PerPage::resolve($request))
            ->withQueryString();

        return view('sales.discounts.index', [
            'requests' => $requests,
            'statuses' => $statuses,
            'activeStatus' => is_string($statusInput) ? $statusInput : '',
            'pendingCount' => SaleDiscountRequest::query()->where('status', DiscountRequestStatus::Pending->value)->count(),
        ]);
    }

    public function show(SaleDiscountRequest $discountRequest): View
    {
        Gate::authorize('view', $discountRequest);

        return view('sales.discounts.show', [
            'discountRequest' => $discountRequest->load([
                'sale:id,sale_number,customer_name_snapshot,subtotal,discount_amount,total_amount,amount_paid,balance_due,refundable_credit,payment_status,status',
                'requester:id,name', 'decider:id,name',
            ]),
        ]);
    }

    public function store(StoreSaleDiscountRequest $request, Sale $sale, RequestSaleDiscount $action): RedirectResponse
    {
        $discountRequest = $action->execute($request->user(), $sale, $request->validated());

        return redirect()->route('sales.show', $sale)->with(
            'status',
            'Discount request '.$discountRequest->id.' submitted for approval. The Sale is unchanged until an administrator approves it.',
        );
    }

    public function approve(
        DecideSaleDiscountRequest $request,
        SaleDiscountRequest $discountRequest,
        DecideSaleDiscount $action,
    ): RedirectResponse {
        Gate::authorize('approve', $discountRequest);
        $note = $request->validated()['decision_note'] ?? null;
        $action->approve($request->user(), $discountRequest, $note === '' ? null : $note);

        return redirect()->route('discounts.show', $discountRequest)
            ->with('status', 'Discount approved and the Sale reconciled.');
    }

    public function decline(
        DecideSaleDiscountRequest $request,
        SaleDiscountRequest $discountRequest,
        DecideSaleDiscount $action,
    ): RedirectResponse {
        Gate::authorize('decline', $discountRequest);
        $note = $request->validated()['decision_note'] ?? null;

        if (! is_string($note) || trim($note) === '') {
            throw ValidationException::withMessages(['decision_note' => 'Say why the discount was declined.']);
        }

        $action->decline($request->user(), $discountRequest, trim($note));

        return redirect()->route('discounts.show', $discountRequest)
            ->with('status', 'Discount declined. The Sale is financially unchanged.');
    }
}
