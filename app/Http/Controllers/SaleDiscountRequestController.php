<?php

namespace App\Http\Controllers;

use App\Actions\Sale\DecideSaleDiscount;
use App\Actions\Sale\RequestDraftSaleDiscount;
use App\Actions\Sale\RequestSaleDiscount;
use App\Enums\DiscountRequestStatus;
use App\Http\Requests\Sale\DecideSaleDiscountRequest;
use App\Http\Requests\Sale\StoreDraftSaleDiscountRequest;
use App\Http\Requests\Sale\StoreSaleDiscountRequest;
use App\Models\Sale;
use App\Models\SaleDiscountRequest;
use App\Models\SaleDraft;
use App\Support\Initials;
use App\Support\PerPage;
use Illuminate\Http\JsonResponse;
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
            ->with(['sale:id,public_id,sale_number,customer_name_snapshot,total_amount', 'draft.customer:id,first_name,last_name', 'requester:id,name', 'decider:id,name'])
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

    /**
     * Raises a discount request for a cart that has not been sold yet.
     *
     * Nothing is sold here: no stock moves, no payment is taken, no Sale exists. The response
     * carries the draft id the Record Sale form must send back, so the approval can be matched to
     * the exact cart, buyer and trading day it was granted for.
     */
    public function storeDraft(StoreDraftSaleDiscountRequest $request, RequestDraftSaleDiscount $action): RedirectResponse|JsonResponse
    {
        $discountRequest = $action->execute($request->user(), $request->validated());

        if ($request->expectsJson()) {
            return response()->json([
                'discount_request_id' => $discountRequest->id,
                'sale_draft_id' => $discountRequest->sale_draft_id,
                'status' => $discountRequest->status->value,
                'requested_amount' => (string) $discountRequest->requested_amount,
            ], 201);
        }

        return back()->with('status', 'Discount request sent for approval.');
    }

    /**
     * The current decision on a draft request, for the Record Sale form to poll while it waits.
     * Read-only, and scoped by the same policy that governs viewing any request.
     */
    public function draftStatus(SaleDiscountRequest $discountRequest): JsonResponse
    {
        Gate::authorize('view', $discountRequest);

        abort_if($discountRequest->sale_draft_id === null, 404);

        return response()->json([
            'status' => $discountRequest->status->value,
            'sale_draft_id' => $discountRequest->sale_draft_id,
            'requested_amount' => (string) $discountRequest->requested_amount,
            'decided_at' => $discountRequest->decided_at?->toIso8601String(),
            'decision_note' => $discountRequest->decision_note,
        ]);
    }

    /**
     * The caller's own unconsumed draft discount requests, newest first.
     *
     * This is what makes "Save as pending" real rather than decorative: the request and its cart
     * already live in the database, so leaving the page loses nothing and the sale can be picked up
     * later from exactly the goods, buyer and trading day that were sent for approval.
     *
     * Scoped to drafts this user created and has not yet spent. A draft whose Sale has been
     * recorded is finished business and is not offered again.
     */
    public function resumableDrafts(Request $request): JsonResponse
    {
        Gate::authorize('createForDraft', SaleDiscountRequest::class);

        $drafts = SaleDraft::query()
            ->with(['customer:id,first_name,last_name,customer_code,phone'])
            ->where('created_by', $request->user()->id)
            ->whereNull('consumed_by_sale_id')
            ->latest('id')
            ->limit(10)
            ->get();

        $rows = $drafts->map(function (SaleDraft $draft): ?array {
            $decision = $draft->latestDecidedRequest() ?? $draft->pendingDiscountRequest();

            if ($decision === null) {
                return null;
            }

            return [
                'sale_draft_id' => $draft->id,
                'discount_request_id' => $decision->id,
                'status' => $decision->status->value,
                'requested_amount' => (string) $decision->requested_amount,
                'reason' => $decision->reason,
                'requested_at' => $decision->requested_at?->toIso8601String(),
                'is_walk_in' => (bool) $draft->is_walk_in,
                'sale_date' => $draft->sale_date?->toDateString(),
                'subtotal' => (string) $draft->subtotal_snapshot,
                'customer' => $draft->customer === null ? null : [
                    'id' => $draft->customer->id,
                    'name' => $draft->customer->full_name,
                    'code' => $draft->customer->customer_code,
                    'phone' => $draft->customer->phone,
                    'initials' => Initials::from($draft->customer->full_name),
                ],
                // The exact lines that were fingerprinted. Rebuilding the cart from these is what
                // keeps a resumed sale matching the approval it is trying to spend. The snapshot
                // also carries the name and price as they stood, which is what the picker shows —
                // CreateSale still re-reads both from the catalogue when the Sale is recorded.
                'lines' => collect($draft->cart_snapshot ?? [])
                    ->map(fn (array $line): array => [
                        'product_id' => (int) ($line['product_id'] ?? 0),
                        'quantity' => (string) ($line['quantity'] ?? '0'),
                        'name' => (string) ($line['name'] ?? ''),
                        'sku' => (string) ($line['sku'] ?? ''),
                        'price' => (string) ($line['unit_price'] ?? '0'),
                    ])->values()->all(),
            ];
        })->filter()->values();

        return response()->json(['drafts' => $rows->all()]);
    }

    public function show(SaleDiscountRequest $discountRequest): View
    {
        Gate::authorize('view', $discountRequest);

        return view('sales.discounts.show', [
            'discountRequest' => $discountRequest->load([
                'sale:id,public_id,sale_number,customer_name_snapshot,subtotal,discount_amount,total_amount,amount_paid,balance_due,refundable_credit,payment_status,status',
                'draft.customer:id,first_name,last_name',
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
