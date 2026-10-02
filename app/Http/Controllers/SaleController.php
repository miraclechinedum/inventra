<?php

namespace App\Http\Controllers;

use App\Actions\Sale\CreateSale;
use App\Actions\Sale\IssueSalePaymentRequest;
use App\Actions\Sale\VoidSale;
use App\Actions\WhatsAppAutomation\WhatsAppAutomationEligibility;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SaleStatus;
use App\Enums\UserRole;
use App\Http\Requests\Sale\StoreSaleRequest;
use App\Http\Requests\Sale\VoidSaleRequest;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleDiscountRequest;
use App\Models\User;
use App\Support\Initials;
use App\Support\Money;
use App\Support\PerPage;
use App\Support\Quantity;
use App\Support\ReceiptPresenter;
use App\Support\SaleCorrectionEligibility;
use App\Support\SaleDiscountEligibility;
use App\Tenancy\CurrentBusiness;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SaleController extends Controller
{
    /**
     * The most rows the PDF report will render.
     *
     * A PDF is a document to be read, not a data feed — that is what the CSV is for, and the CSV
     * chunks rather than capping. Rendering tens of thousands of rows through dompdf would exhaust
     * memory long before it produced anything anyone would open, so the cap is explicit and the
     * document says when it has been reached.
     */
    private const PDF_ROW_LIMIT = 2000;

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Sale::class);
        $salesRep = $request->user()->role === UserRole::SalesRep;

        // The seller's role is read from the User for the panel's "Recorded by" line. The *name*
        // stays the Sale's own immutable snapshot — a seller renamed or promoted since must not
        // restate who recorded a past sale — so this only supplies the role, and only for the
        // sellers on this page.
        $sales = $this->filteredSales($request)
            ->withCount('items')
            ->with('seller:id,role')
            ->paginate(PerPage::resolve($request))
            ->withQueryString();

        return view('sales.index', [
            'sales' => $sales,
            'sellers' => $salesRep ? collect() : User::query()->inCurrentBusiness()->orderBy('name')->get(['id', 'name']),
            'salesRep' => $salesRep,
            'customers' => Customer::query()->orderBy('first_name')->limit(200)
                ->get(['id', 'customer_code', 'first_name', 'last_name']),
        ]);
    }

    /**
     * The line items of one Sale, for the detail panel.
     *
     * Behind the same `view` policy as the Sale itself, and returning only what the panel renders —
     * the product name as it was sold, the quantity and the line total. No cost price, no SKU
     * lookup, nothing the list does not already show.
     */
    public function lines(Sale $sale): JsonResponse
    {
        Gate::authorize('view', $sale);

        return response()->json([
            'lines' => $sale->items()->get()->map(fn ($item): array => [
                'name' => $item->product_name_snapshot,
                'quantity' => Quantity::trim((string) $item->quantity),
                // `compact`, not `format`: this feeds the Sales list side panel, where every other
                // figure — the table's Amount column and the panel's own Total — drops a whole
                // naira's `.00`. The value is the item's settled `line_total` (unit price times
                // quantity), never the unit price, and nothing here recalculates it.
                'total' => Money::compact((string) $item->line_total),
            ])->all(),
        ]);
    }

    /**
     * The Sales the caller may see, narrowed by the filter bar.
     *
     * Shared by the list, the CSV export and the PDF report, so the three can never disagree about
     * what "the current filters" mean, and so the Sales Rep scope — own sales only — is applied
     * once rather than remembered three times. Any new export belongs here too.
     *
     * @return Builder<Sale>
     */
    private function filteredSales(Request $request): Builder
    {
        $searchInput = $request->query('search');
        $statusInput = $request->query('status');
        $methodInput = $request->query('payment_method');
        $paymentInput = $request->query('payment_status');
        $customerInput = $request->query('customer');
        $sellerInput = $request->query('sold_by');
        $fromInput = $request->query('from');
        $toInput = $request->query('to');
        $search = is_string($searchInput) ? trim($searchInput) : '';
        $escaped = $this->escapeLike($search);
        $salesRep = $request->user()->role === UserRole::SalesRep;

        return Sale::query()
            ->when($salesRep, fn ($query) => $query->where('sold_by', $request->user()->id))
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('sale_number', 'like', mb_strtoupper($escaped).'%')
                ->orWhere('customer_name_snapshot', 'like', $escaped.'%')
                ->orWhere('customer_code_snapshot', 'like', mb_strtoupper($escaped).'%')
                ->orWhere('customer_phone_snapshot', 'like', $escaped.'%')))
            ->when(is_string($statusInput) && in_array($statusInput, array_column(SaleStatus::cases(), 'value'), true), fn ($query) => $query->where('status', $statusInput))
            ->when(is_string($methodInput) && in_array($methodInput, array_column(PaymentMethod::cases(), 'value'), true), fn ($query) => $query->where('payment_method', $methodInput))
            ->when(is_string($paymentInput) && in_array($paymentInput, array_column(PaymentStatus::cases(), 'value'), true), fn ($query) => $query->where('payment_status', $paymentInput))
            // "walk-in" is a filter value in its own right, because a walk-in has no customer id to
            // select and would otherwise be unreachable from this control.
            ->when($customerInput === 'walk-in', fn ($query) => $query->where('is_walk_in', true))
            ->when(is_string($customerInput) && ctype_digit($customerInput), fn ($query) => $query->where('customer_id', (int) $customerInput))
            ->when(! $salesRep && is_string($sellerInput) && ctype_digit($sellerInput), fn ($query) => $query->where('sold_by', $sellerInput))
            ->when(is_string($fromInput) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromInput), fn ($query) => $query->whereDate('sale_date', '>=', $fromInput))
            ->when(is_string($toInput) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $toInput), fn ($query) => $query->whereDate('sale_date', '<=', $toInput))
            // Filtered by trading day to agree with the Sales Report; ordered by it too, so a
            // backdated sale files under the day it happened rather than jumping to the top.
            ->latest('sale_date')
            ->latest('id');
    }

    /**
     * The filtered Sales as a CSV.
     *
     * Exactly the rows the list is showing, through the same query, so the file cannot contain a
     * Sale the caller is not allowed to see. It carries only what the Sales screens already show:
     * no cost price, no margin, no internal note, no audit or security column. Dates are the
     * trading day, matching the list and the reports.
     */
    public function export(Request $request): StreamedResponse
    {
        Gate::authorize('viewAny', Sale::class);

        $filename = 'sales-'.CarbonImmutable::now(config('business.timezone'))->format('Y-m-d-His').'.csv';

        // The body streams after the controller returns, so it runs inside the request's Business
        // explicitly rather than relying on whatever context is live when the stream is consumed.
        $business = app(CurrentBusiness::class)->get();

        return response()->streamDownload(fn () => app(CurrentBusiness::class)->run($business, function () use ($request): void {
            $handle = fopen('php://output', 'wb');
            fputcsv($handle, ['Sale number', 'Date', 'Customer', 'Items', 'Subtotal', 'Discount', 'Total', 'Amount paid', 'Balance due', 'Payment status', 'Status', 'Sold by']);

            // Chunked so a long history streams rather than being assembled in memory.
            $this->filteredSales($request)->withCount('items')->chunk(200, function ($sales) use ($handle): void {
                foreach ($sales as $sale) {
                    fputcsv($handle, [
                        $sale->sale_number,
                        $sale->sale_date->toDateString(),
                        $sale->customer_name_snapshot,
                        $sale->items_count,
                        $sale->subtotal,
                        $sale->discount_amount,
                        $sale->total_amount,
                        $sale->amount_paid,
                        $sale->balance_due,
                        $sale->payment_status->value,
                        $sale->status->value,
                        $sale->sold_by_name_snapshot,
                    ]);
                }
            });

            fclose($handle);
        }), $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * The same filtered Sales as a printable PDF report.
     *
     * A list export, not a receipt: `receiptPdf` above renders one Sale for a customer, this renders
     * the sales register for the business, and the two share nothing but the PDF facade.
     *
     * The rows come from `filteredSales()` — the same builder the list and the CSV use — so all
     * three show the same Sales under the same filters and the same Sales Rep narrowing, and the
     * document cannot contain a Sale the caller may not see. Authorization is the list's own
     * `viewAny`, because this is the list.
     *
     * Totals are summed from the rows the report actually prints, so the figure at the foot always
     * describes the page above it. Unlike the CSV this is bounded — a PDF of fifty thousand rows
     * helps nobody and would exhaust memory rendering — so it takes a hard cap and says plainly in
     * the document when the filters matched more than it shows.
     */
    public function exportPdf(Request $request): Response
    {
        Gate::authorize('viewAny', Sale::class);

        $query = $this->filteredSales($request)->withCount('items');
        $matched = (clone $query)->count();
        $sales = $query->limit(self::PDF_ROW_LIMIT)->get();

        $pdf = Pdf::loadView('sales.export-pdf', [
            'sales' => $sales,
            'matched' => $matched,
            'limit' => self::PDF_ROW_LIMIT,
            'filters' => $this->describeFilters($request),
            'generatedAt' => CarbonImmutable::now(config('business.timezone')),
            // Summed over what is printed, in the same decimal arithmetic the rest of the
            // application uses for money. Nothing here recalculates a Sale; it only adds up
            // columns the Sales have already settled.
            'totals' => [
                'total' => $sales->reduce(fn (string $carry, Sale $sale): string => bcadd($carry, (string) $sale->total_amount, 2), '0.00'),
                'paid' => $sales->reduce(fn (string $carry, Sale $sale): string => bcadd($carry, (string) $sale->amount_paid, 2), '0.00'),
                'balance' => $sales->reduce(fn (string $carry, Sale $sale): string => bcadd($carry, (string) $sale->balance_due, 2), '0.00'),
            ],
        ])->setPaper('a4', 'landscape');

        return $pdf->download('sales-'.CarbonImmutable::now(config('business.timezone'))->format('Y-m-d-His').'.pdf');
    }

    /**
     * The filter bar in words, for the report's header, so a printed page says what it is a report
     * of rather than leaving the reader to guess.
     *
     * Only values this controller has already accepted are described. Anything that failed the
     * validation in `filteredSales()` did not narrow the query and so is not claimed here — the
     * summary would otherwise describe a filter that was never applied.
     *
     * @return list<string>
     */
    private function describeFilters(Request $request): array
    {
        $described = [];
        $from = $request->query('from');
        $to = $request->query('to');
        $valid = static fn ($date): bool => is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1;

        if ($valid($from) && $valid($to)) {
            $described[] = 'Dated '.$from.' to '.$to;
        } elseif ($valid($from)) {
            $described[] = 'Dated from '.$from;
        } elseif ($valid($to)) {
            $described[] = 'Dated up to '.$to;
        }

        $customer = $request->query('customer');

        if ($customer === 'walk-in') {
            $described[] = 'Walk-in sales';
        } elseif (is_string($customer) && ctype_digit($customer)) {
            $found = Customer::query()->find((int) $customer);
            $described[] = 'Customer: '.($found?->full_name ?? 'unknown');
        }

        $payment = $request->query('payment_status');

        if (is_string($payment) && in_array($payment, array_column(PaymentStatus::cases(), 'value'), true)) {
            $described[] = 'Payment status: '.ucfirst($payment);
        }

        $status = $request->query('status');

        if (is_string($status) && in_array($status, array_column(SaleStatus::cases(), 'value'), true)) {
            $described[] = 'Sale status: '.ucfirst($status);
        }

        $search = $request->query('search');

        if (is_string($search) && trim($search) !== '') {
            $described[] = 'Matching "'.trim($search).'"';
        }

        return $described;
    }

    /**
     * The Record Sale page, and the product type-ahead behind it.
     *
     * The JSON branch serves the product combobox. It returns only what the picker needs to render
     * a row and price a line — never cost price, which no sales screen is entitled to see.
     */
    public function create(Request $request): View|JsonResponse
    {
        Gate::authorize('create', Sale::class);
        $searchInput = $request->query('product_search');
        $search = is_string($searchInput) ? trim($searchInput) : '';
        $escaped = $this->escapeLike($search);

        $products = Product::query()->active()
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('sku', 'like', mb_strtoupper($escaped).'%')
                ->orWhere('name', 'like', $escaped.'%')))
            ->orderBy('name')->limit(100)
            ->get(['id', 'sku', 'name', 'unit', 'selling_price', 'current_stock']);

        if ($request->expectsJson()) {
            return response()->json(['products' => $products->map($this->productPayload(...))->all()]);
        }

        return view('sales.create', [
            'completed' => $this->completedSale($request),
            'restored' => $this->restoredInput($request),
            // A customer carried in from their profile, so Record Sale opens on the person the
            // operator was already looking at. Resolved here from the id rather than trusted from
            // the URL: only an active customer resolves, and StoreSaleRequest validates the id
            // again on submission regardless of what was preselected.
            'preselected' => $this->preselectedCustomer($request),
            // A modest first page for the picker to open with; everything after is searched.
            'products' => $products->take(40)->map($this->productPayload(...))->values(),
            'customers' => Customer::query()->active()->orderBy('first_name')->limit(40)
                ->get(['id', 'customer_code', 'first_name', 'last_name', 'phone'])
                ->map($this->customerPayload(...))->values(),
            'today' => CarbonImmutable::now(config('business.timezone'))->toDateString(),
            'canRequestDiscount' => Gate::allows('createForDraft', SaleDiscountRequest::class),
        ]);
    }

    /**
     * What the operator had entered when a submission was rejected, rebuilt from authoritative
     * records so a failed sale never has to be keyed in twice.
     *
     * The division is deliberate. Old input supplies only the operator's *choices* — which customer,
     * which products, how many, what they were paid, which trading day. Everything that describes those
     * choices — a product's name, price, stock and whether it is still sellable; a customer's name
     * and number — is re-read from the database now. A price echoed back from the browser would be
     * a price the user could have edited, and CreateSale would reject it anyway; showing it would
     * only mislead.
     *
     * Anything that no longer resolves is dropped rather than guessed at: an archived product or a
     * deactivated customer simply does not come back, and the form says so through the validation
     * message that accompanies it.
     *
     * @return array<string, mixed>|null
     */
    private function restoredInput(Request $request): ?array
    {
        if (! $request->session()->hasOldInput()) {
            return null;
        }

        $walkIn = (bool) $request->old('is_walk_in');
        $customerId = $request->old('customer_id');
        $customer = null;

        // Only a customer who is still active comes back. One deactivated between attempts is
        // dropped, so the form cannot quietly resubmit an identity the server would now refuse.
        if (! $walkIn && is_numeric($customerId)) {
            $found = Customer::query()->active()->find((int) $customerId);
            $customer = $found === null ? null : $this->customerPayload($found);
        }

        $lines = [];
        $oldProducts = $request->old('products');

        if (is_array($oldProducts)) {
            $ids = collect($oldProducts)
                ->map(fn ($line) => is_array($line) ? ($line['product_id'] ?? null) : null)
                ->filter(fn ($id) => is_numeric($id))
                ->map(fn ($id) => (int) $id);

            // One query, and only sellable products: an archived one is not restored, because it
            // cannot be sold and offering it again would just fail the same way.
            $catalogue = Product::query()->active()->whereIn('id', $ids->all())
                ->get(['id', 'sku', 'name', 'unit', 'selling_price', 'current_stock'])
                ->keyBy('id');

            foreach ($oldProducts as $line) {
                if (! is_array($line) || ! is_numeric($line['product_id'] ?? null)) {
                    continue;
                }

                $product = $catalogue->get((int) $line['product_id']);

                if ($product === null) {
                    continue;
                }

                $quantity = (string) ($line['quantity'] ?? '1');

                $lines[] = [
                    // Current name, SKU, price and stock — never what the browser last sent.
                    'product' => $this->productPayload($product),
                    'quantity' => $quantity === '' ? '1' : $quantity,
                ];
            }
        }

        return [
            'isWalkIn' => $walkIn,
            'customer' => $customer,
            'lines' => $lines,
            'saleDate' => is_string($request->old('sale_date')) ? $request->old('sale_date') : null,
            'amountPaid' => is_string($request->old('amount_paid')) ? $request->old('amount_paid') : null,
            // The draft is re-checked by CreateSale against the cart, buyer and date it is finally
            // given, so carrying the id back cannot spend a stale approval — a changed context is
            // refused there. It is dropped entirely when the cart could not be restored intact.
            'saleDraftId' => $lines !== [] && is_numeric($request->old('sale_draft_id'))
                ? (int) $request->old('sale_draft_id')
                : null,
        ];
    }

    /**
     * The Sale just recorded, if this page was reached from a successful submission.
     *
     * Read back from the database rather than carried through the session, so the success modal
     * shows the Sale as it truly is. The WhatsApp state is *reported*, never caused: the
     * post-purchase automation already queued a message if the sale was paid and the customer
     * eligible, and a second send here would be a duplicate message to a real person.
     *
     * @return array<string, mixed>|null
     */
    private function completedSale(Request $request): ?array
    {
        $saleId = $request->session()->get('completedSale');

        if (! is_int($saleId) && ! ctype_digit((string) $saleId)) {
            return null;
        }

        // The confirmation banner belongs to the Sales list, which is where closing the modal
        // goes next, so the flash is kept for one more request.
        $request->session()->reflash();

        $sale = Sale::query()->with('items')->find((int) $saleId);

        if ($sale === null || ! Gate::allows('view', $sale)) {
            return null;
        }

        $message = $sale->whatsappMessages()->latest('id')->first();
        $customer = $sale->customer_id === null ? null : Customer::query()->find($sale->customer_id);
        $eligible = $customer !== null && WhatsAppAutomationEligibility::permitsCustomer($customer);

        return [
            'sale' => $sale,
            'receipt' => ReceiptPresenter::for($sale),
            // Exactly one of these describes the automation, and the modal says so plainly rather
            // than offering an action that would duplicate a message already on its way.
            'whatsapp' => match (true) {
                $message !== null => 'queued',
                $sale->isWalkIn() => 'walk_in',
                $customer === null => 'walk_in',
                ! $eligible => 'not_eligible',
                default => 'available',
            },
            // For the consent line on the completion modal. The Sale's own snapshot is the buyer's
            // name; this is the live Customer, which is who consent belongs to.
            'customerName' => $customer?->full_name,
            'customerPhone' => $customer?->phone,
        ];
    }

    /** Type-ahead for the customer combobox. Active customers only, matched on name, code or phone. */
    public function customerSearch(Request $request): JsonResponse
    {
        Gate::authorize('create', Sale::class);
        $term = $request->query('q');
        $term = is_string($term) ? trim($term) : '';
        $escaped = $this->escapeLike($term);

        $customers = Customer::query()->active()
            ->when($term !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('first_name', 'like', $escaped.'%')
                ->orWhere('last_name', 'like', $escaped.'%')
                ->orWhere('customer_code', 'like', mb_strtoupper($escaped).'%')
                ->orWhere('phone', 'like', $escaped.'%')))
            ->orderBy('first_name')->limit(20)
            ->get(['id', 'customer_code', 'first_name', 'last_name', 'phone']);

        return response()->json(['customers' => $customers->map($this->customerPayload(...))->all()]);
    }

    /**
     * The customer a Record Sale link arrived with, if there is one.
     *
     * Read from the database rather than the query string: the URL supplies an id and nothing else,
     * so a tampered link can only ever name a customer who exists and is active. Everything shown —
     * the name, the code, the phone — comes from the record.
     *
     * @return array<string, mixed>|null
     */
    private function preselectedCustomer(Request $request): ?array
    {
        $id = $request->query('customer');

        if (! is_string($id) || ! ctype_digit($id)) {
            return null;
        }

        $customer = Customer::query()->active()->find((int) $id);

        return $customer === null ? null : $this->customerPayload($customer);
    }

    /** @return array<string, mixed> */
    private function customerPayload(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'name' => $customer->full_name,
            'code' => $customer->customer_code,
            'phone' => $customer->phone,
            'initials' => Initials::from($customer->full_name),
        ];
    }

    /**
     * What the product picker and cart are allowed to know. `selling_price` prices the line the
     * user sees; `current_stock` drives the inline stock warning. Cost price is absent by design.
     *
     * @return array<string, mixed>
     */
    private function productPayload(Product $product): array
    {
        return [
            'id' => $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'unit' => $product->unit->value,
            'price' => (string) $product->selling_price,
            // Trimmed for display: the column is decimal:3, so it arrives as "40.000" and would
            // otherwise read that way on screen. Trimming is presentation only and never rounds —
            // "0.5" stays "0.5" — so the picker's stock comparisons are unaffected, and CreateSale
            // re-reads the real column under a row lock regardless of what is shown here.
            'stock' => Quantity::trim((string) $product->current_stock),
        ];
    }

    public function store(StoreSaleRequest $request, CreateSale $action): RedirectResponse
    {
        $sale = $action->execute($request->user(), $request->validated());

        // Back to a fresh Record Sale page carrying the completion state, rather than straight to
        // the Sale. Recording one sale is usually followed by recording another, and the success
        // modal has to report what actually happened to the receipt — including whether CreateSale
        // already queued a WhatsApp message, which this only reads and never triggers again.
        return redirect()->route('sales.create')
            ->with('completedSale', $sale->id)
            // Read by the Sales list once the completion modal is closed. Flashed twice over
            // (create, then index) via `reflash` there, so it survives the one hop in between.
            ->with('saleRecorded', 'Sale recorded successfully — '.$sale->sale_number);
    }

    public function show(Request $request, Sale $sale, IssueSalePaymentRequest $issuePaymentRequest): View
    {
        Gate::authorize('view', $sale);
        $sale->load(['items', 'payments', 'voider:id,name', 'customer:id,phone,is_active,whatsapp_opt_in,whatsapp_opt_in_at,whatsapp_opt_out_at']);
        if (in_array($request->user()->role, [UserRole::Admin, UserRole::Manager], true)) {
            $sale->load(['returns.items', 'refunds']);
        }
        $paymentToken = Gate::allows('recordPayment', $sale)
            && $sale->status === SaleStatus::Completed
            && $sale->payment_status !== PaymentStatus::Paid
            ? $issuePaymentRequest->execute($sale, $request->user(), $request->session())
            : null;

        return view('sales.show', [
            'sale' => $sale,
            'discountRequests' => $sale->discountRequests()->with('decider:id,name')->latest('id')->limit(5)->get(),
            'discountBlockedReason' => SaleDiscountEligibility::blockedReason($sale),
            'correctionCount' => $sale->corrections()->count(),
            'correctionBlockedReason' => SaleCorrectionEligibility::blockedReason($sale),
            'canRequestDiscount' => Gate::allows('create', [SaleDiscountRequest::class, $sale]),
            'paymentToken' => $paymentToken,
            'paymentMethods' => PaymentMethod::cases(),
        ]);
    }

    public function receipt(Sale $sale): View
    {
        Gate::authorize('view', $sale);

        return view('sales.receipt', ['sale' => $sale->load(['items', 'payments'])]);
    }

    /**
     * The same receipt as a downloadable PDF.
     *
     * Behind the same `view` policy as the printable receipt, because it carries the same
     * information — a PDF route that were any more permissive would be a way around the screen.
     * The document is rendered from a finalized Sale's own columns via ReceiptPresenter, so it can
     * never show a figure the ledger disagrees with, and cost price, internal notes and audit data
     * are simply never read.
     */
    public function receiptPdf(Sale $sale): Response
    {
        Gate::authorize('view', $sale);

        return Pdf::loadView('sales.receipt-pdf', ['sale' => $sale->load('items')])
            ->setPaper('a4')
            ->download($sale->sale_number.'.pdf');
    }

    public function void(VoidSaleRequest $request, Sale $sale, VoidSale $action): RedirectResponse
    {
        $action->execute($request->user(), $sale, $request->validated('reason'));

        return back()->with('status', 'Sale voided and stock restored.');
    }

    public function activity(Request $request, Sale $sale): View
    {
        Gate::authorize('viewAudit', $sale);

        return view('sales.activity', [
            'sale' => $sale,
            'events' => AuditLog::query()
                ->where('auditable_type', $sale->getMorphClass())
                ->where('auditable_id', $sale->id)
                ->with('actor:id,name')
                ->latest('created_at')
                ->latest('id')
                ->paginate(PerPage::resolve($request))->withQueryString(),
        ]);
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
