<?php

namespace App\Http\Controllers;

use App\Actions\Sale\IssueRefundRequest;
use App\Actions\Sale\IssueReturnRequest;
use App\Actions\Sale\RecordSaleRefund;
use App\Actions\Sale\RecordSaleReturn;
use App\Enums\ReturnDisposition;
use App\Enums\SaleStatus;
use App\Enums\UserRole;
use App\Http\Requests\RecordSaleRefundRequest;
use App\Http\Requests\RecordSaleReturnRequest;
use App\Models\Sale;
use App\Models\SaleRefund;
use App\Models\SaleReturn;
use App\Support\Money;
use App\Support\PerPage;
use App\Support\Quantity;
use App\Support\ReturnSettlementPreview;
use App\Support\SaleFinancials;
use App\Tenancy\CurrentBusiness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SaleReturnController extends Controller
{
    private function authorizeUser(Request $request): void
    {
        abort_unless(in_array($request->user()->role, [UserRole::Admin, UserRole::Manager], true), 403);
    }

    public function index(Request $request): View
    {
        $this->authorizeUser($request);

        $filters = $this->filters($request);
        $search = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($filters['search'], 0, 255));
        $rows = SaleReturn::query()->withCount('items')
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query->where('return_number', 'like', $search.'%')->orWhere('sale_number_snapshot', 'like', $search.'%')))
            ->when(ctype_digit($filters['customer']), fn ($query) => $query->where('customer_id', (int) $filters['customer']))
            ->when(ctype_digit($filters['staff']), fn ($query) => $query->where('returned_by', (int) $filters['staff']))
            ->when($this->date($filters['from']), fn ($query) => $query->whereDate('returned_at', '>=', $filters['from']))
            ->when($this->date($filters['to']), fn ($query) => $query->whereDate('returned_at', '<=', $filters['to']))
            ->latest('returned_at')->latest('id')->paginate(PerPage::resolve($request))->withQueryString();

        return view('returns.index', compact('rows', 'filters'));
    }

    public function create(Request $request, Sale $sale, IssueReturnRequest $tokens): View
    {
        $this->authorizeUser($request);
        abort_unless($sale->status->value === 'completed', 422);
        $sale->load('items');
        $prior = DB::table('sale_return_items')->where('sale_return_items.business_id', app(CurrentBusiness::class)->id())->selectRaw('sale_item_id,SUM(quantity_returned) quantity')->whereIn('sale_item_id', $sale->items->pluck('id'))->groupBy('sale_item_id')->pluck('quantity', 'sale_item_id');

        return view('returns.create', ['sale' => $sale, 'prior' => $prior, 'dispositions' => ReturnDisposition::cases(), 'requestToken' => $tokens->execute($request->user(), $sale, $request->session())]);
    }

    /**
     * The Return form as a fragment, for the Sales list's side panel.
     *
     * The same authorization, the same eligibility and the same token as the full page — it issues
     * through `IssueReturnRequest` and posts to the same `store`, so the panel is a second doorway
     * onto one workflow rather than a second implementation of it.
     *
     * Why a Return and not a Sale correction: the panel's design is item-level — pick the goods
     * that came back, say how many, give a reason, put stock away. That is what a Return is.
     * Correcting a Sale rewrites what was recorded as if the original entry were wrong; a Return
     * leaves the Sale standing as evidence of what was sold and books the goods coming back
     * against it. The existing correction form says as much in its own words, pointing the operator
     * at Record Return for returned goods.
     *
     * The remaining returnable quantity per line is computed here, from `sale_return_items`, so a
     * line already sent back cannot be offered again. It is a convenience for the form: the action
     * re-derives it under a row lock and rejects anything over it regardless of what was submitted.
     */
    public function panel(Request $request, Sale $sale, IssueReturnRequest $tokens): View
    {
        $this->authorizeUser($request);
        abort_unless($sale->status === SaleStatus::Completed, 422);

        $sale->load('items');

        $prior = DB::table('sale_return_items')->where('sale_return_items.business_id', app(CurrentBusiness::class)->id())
            ->selectRaw('sale_item_id, SUM(quantity_returned) quantity')
            ->whereIn('sale_item_id', $sale->items->pluck('id'))
            ->groupBy('sale_item_id')
            ->pluck('quantity', 'sale_item_id');

        // Only lines with something left to send back can be chosen; the rest are shown as
        // already returned rather than quietly omitted, so the operator can see why.
        $lines = $sale->items->map(function ($item) use ($prior): array {
            $returned = (string) ($prior[$item->id] ?? '0');
            $remaining = bcsub((string) $item->quantity, $returned, 3);

            return [
                'id' => $item->id,
                'name' => $item->product_name_snapshot,
                'unit' => $item->unit_snapshot,
                'sold' => Quantity::trim((string) $item->quantity),
                'returned' => Quantity::trim($returned),
                'remaining' => Quantity::trim($remaining),
                'remainingRaw' => $remaining,
                // The price as sold. The Product's price today is deliberately not read.
                'unitPrice' => Money::compact((string) $item->unit_price),
                'unitPriceRaw' => (string) $item->unit_price,
                'exhausted' => bccomp($remaining, '0', 3) <= 0,
            ];
        })->values();

        return view('returns._panel', [
            'sale' => $sale,
            'lines' => $lines,
            'dispositions' => ReturnDisposition::cases(),
            'requestToken' => $tokens->execute($request->user(), $sale, $request->session()),
            // What the Sale currently owes, so the panel can say whether returning goods cancels a
            // debt or hands cash back. Recomputed authoritatively when the return is recorded.
            'balance' => Money::compact(SaleFinancials::lockedState($sale)['balance']),
        ]);
    }

    /**
     * What the pending selection would do, as JSON for the panel's summary line.
     *
     * The browser sends only what the operator chose — which sale items, how many of each, and
     * whether the goods go back on the shelf. Every figure comes back from
     * `ReturnSettlementPreview`, which splits the money the same way RecordSaleReturn does, so the
     * sentence on screen and the entry in the ledger are produced by one piece of arithmetic.
     *
     * Prices are never accepted from the browser. The unit price is read from the Sale's own items
     * here, so a tampered payload can only ask about lines that exist on this Sale and is priced as
     * it was sold. Quantities are clamped to what is actually still returnable — the same ceiling
     * the action enforces under a lock when it commits.
     *
     * This is informational and writes nothing. Authorization is the same as the panel's.
     */
    public function preview(Request $request, Sale $sale): JsonResponse
    {
        $this->authorizeUser($request);
        abort_unless($sale->status === SaleStatus::Completed, 422);

        $validated = $request->validate([
            'items' => ['array'],
            'items.*.sale_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'regex:/^(?:0|[1-9]\d{0,11})(?:\.\d{1,3})?$/'],
            'restock' => ['nullable', 'boolean'],
        ]);

        $items = $sale->items()->get()->keyBy('id');
        $prior = DB::table('sale_return_items')->where('sale_return_items.business_id', app(CurrentBusiness::class)->id())
            ->selectRaw('sale_item_id, SUM(quantity_returned) quantity')
            ->whereIn('sale_item_id', $items->keys())
            ->groupBy('sale_item_id')
            ->pluck('quantity', 'sale_item_id');

        $lines = [];

        foreach ($validated['items'] ?? [] as $line) {
            $item = $items->get((int) $line['sale_item_id']);

            // A line that is not on this Sale is simply not priced. The preview describes the Sale
            // in front of the operator, never one they have not been shown.
            if ($item === null) {
                continue;
            }

            $remaining = bcsub((string) $item->quantity, (string) ($prior[$item->id] ?? '0'), 3);
            $quantity = bccomp((string) $line['quantity'], $remaining, 3) > 0 ? $remaining : (string) $line['quantity'];

            if (bccomp($quantity, '0', 3) <= 0) {
                continue;
            }

            $lines[] = ['quantity' => $quantity, 'unit_price' => (string) $item->unit_price];
        }

        $settlement = ReturnSettlementPreview::describe($sale, $lines, (bool) ($validated['restock'] ?? true));

        return response()->json([
            // Formatted for display alongside the raw decimals, so the panel prints money exactly
            // as the rest of Inventra does without reformatting it itself.
            'sentence' => $settlement['sentence'],
            // The same sentence in labelled parts, so the panel can bold the amounts without
            // being given markup to render.
            'clauses' => $settlement['clauses'],
            'return_value' => $settlement['merchandise'],
            'cash_refund' => $settlement['credit'],
            'balance_reduction' => $settlement['reduction'],
            'restock_units' => $settlement['restock_units'],
            'non_restock_units' => $settlement['non_restock_units'],
        ]);
    }

    public function store(RecordSaleReturnRequest $request, Sale $sale, RecordSaleReturn $action): RedirectResponse
    {
        $return = $action->execute($request->user(), $sale, $request->validated(), $request->session()->getId());

        // A return recorded from the Sales-list panel goes back to the list, which re-renders every
        // row from the database — so the settlement rows the operator just changed are read back
        // rather than adjusted in place and assumed. The same `return_to` convention the correction
        // form uses, and the value is compared against a literal, never redirected to.
        if ($request->input('return_to') === 'index') {
            return redirect()->route('sales.index')
                ->with('status', 'Return '.$return->return_number.' recorded.');
        }

        return redirect()->route('returns.show', $return)->with('status', 'Return recorded.');
    }

    public function show(Request $request, SaleReturn $return): View
    {
        $this->authorizeUser($request);

        return view('returns.show', ['return' => $return->load('items')]);
    }

    public function receipt(Request $request, SaleReturn $return): View
    {
        $this->authorizeUser($request);

        return view('returns.receipt', ['return' => $return->load('items')]);
    }

    public function refundCreate(Request $request, Sale $sale, IssueRefundRequest $tokens): View
    {
        $this->authorizeUser($request);
        $state = DB::transaction(fn () => SaleFinancials::lockedState(Sale::lockForUpdate()->findOrFail($sale->id)));

        return view('returns.refund', compact('sale', 'state') + ['requestToken' => $tokens->execute($request->user(), $sale, $request->session())]);
    }

    public function refundStore(RecordSaleRefundRequest $request, Sale $sale, RecordSaleRefund $action): RedirectResponse
    {
        $refund = $action->execute($request->user(), $sale, $request->validated(), $request->session()->getId());

        return redirect()->route('refunds.show', $refund)->with('status', 'Refund recorded.');
    }

    public function refundIndex(Request $request): View
    {
        $this->authorizeUser($request);
        $filters = $this->filters($request);
        $search = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($filters['search'], 0, 255));
        $rows = SaleRefund::query()
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query->where('refund_number', 'like', $search.'%')->orWhere('sale_number_snapshot', 'like', $search.'%')))
            ->when(ctype_digit($filters['customer']), fn ($query) => $query->where('customer_id', (int) $filters['customer']))
            ->when(ctype_digit($filters['staff']), fn ($query) => $query->where('refunded_by', (int) $filters['staff']))
            ->when(in_array($filters['method'], ['cash', 'transfer'], true), fn ($query) => $query->where('payment_method', $filters['method']))
            ->when($this->date($filters['from']), fn ($query) => $query->whereDate('refunded_at', '>=', $filters['from']))
            ->when($this->date($filters['to']), fn ($query) => $query->whereDate('refunded_at', '<=', $filters['to']))
            ->latest('refunded_at')->latest('id')->paginate(PerPage::resolve($request))->withQueryString();

        return view('returns.refunds-index', compact('rows', 'filters'));
    }

    public function refundShow(Request $request, SaleRefund $refund): View
    {
        $this->authorizeUser($request);

        return view('returns.refund-show', compact('refund'));
    }

    public function refundReceipt(Request $request, SaleRefund $refund): View
    {
        $this->authorizeUser($request);

        return view('returns.refund-receipt', compact('refund'));
    }

    private function filters(Request $request): array
    {
        return collect(['search', 'customer', 'staff', 'method', 'from', 'to'])->mapWithKeys(fn (string $key) => [$key => is_string($request->query($key)) ? trim($request->query($key)) : ''])->all();
    }

    private function date(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
    }
}
