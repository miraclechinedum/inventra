<?php

namespace App\Http\Controllers;

use App\Actions\Customer\CreateCustomer;
use App\Actions\Customer\SetCustomerActiveState;
use App\Actions\Customer\SetCustomerPhoto;
use App\Actions\Customer\SetWhatsAppConsent;
use App\Actions\Customer\UpdateCustomer;
use App\Enums\UserRole;
use App\Http\Requests\Customer\CustomerPhotoRequest;
use App\Http\Requests\Customer\SetWhatsAppConsentRequest;
use App\Http\Requests\Customer\StoreCustomerRequest;
use App\Http\Requests\Customer\UpdateCustomerRequest;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Support\CanonicalLoginIdentifier;
use App\Support\ImageStore;
use App\Support\PerPage;
use App\Tenancy\CurrentBusiness;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerController extends Controller
{
    /**
     * The customer list.
     *
     * Total spent and last purchase come from correlated subqueries rather than a per-row lookup,
     * so ten customers cost one query rather than twenty-one. Both read the same completed-sale
     * scope the Customer Performance report uses, so the list cannot disagree with the report.
     */
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Customer::class);
        $searchInput = $request->query('search');
        $statusInput = $request->query('status');
        $whatsAppInput = $request->query('whatsapp');
        $search = is_string($searchInput) ? trim($searchInput) : '';
        $escaped = $this->escapeLike($search);
        $phone = CanonicalLoginIdentifier::normalizeNigerianPhone($search);
        $phoneFragment = $this->phoneSearchFragment($search);
        $canManageStatus = in_array($request->user()->role, [UserRole::Admin, UserRole::Manager], true);

        // Sales a customer's figures are drawn from: completed only, so a voided sale never counts
        // toward what someone has spent. Deliberately the same scope as BusinessReports::customers.
        $completedSales = Sale::query()
            ->whereColumn('sales.customer_id', 'customers.id')
            ->where('sales.status', 'completed');

        $customers = Customer::query()
            ->addSelect(['*'])
            ->selectSub((clone $completedSales)->selectRaw('COALESCE(SUM(total_amount), 0)'), 'total_spent')
            ->selectSub((clone $completedSales)->selectRaw('MAX(sale_date)'), 'last_sale_date')
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('first_name', 'like', $escaped.'%')
                ->orWhere('last_name', 'like', $escaped.'%')
                ->orWhereRaw("CONCAT(first_name, ' ', COALESCE(last_name, '')) LIKE ?", [$escaped.'%'])
                ->orWhere('customer_code', 'like', mb_strtoupper($escaped).'%')
                ->orWhere('email', 'like', mb_strtolower($escaped).'%')
                // The tag is searchable because the empty state promises "name, phone, or vehicle",
                // and the tag is where a vehicle is recorded. Matched anywhere in the value, since
                // "Camry" should find "Toyota Camry 2012".
                ->orWhere('tag', 'like', '%'.$escaped.'%')
                ->when($phone, fn ($query) => $query->orWhere('phone', 'like', $phone.'%'))
                ->when($phone, fn ($query) => $query->orWhere('whatsapp_phone', 'like', $phone.'%'))
                ->when($phoneFragment, fn ($query) => $query->orWhereRaw("REPLACE(phone, '+', '') LIKE ?", ['%'.$phoneFragment.'%']))))
            ->when(! $canManageStatus, fn ($query) => $query->active())
            ->when($canManageStatus && in_array($statusInput, ['active', 'inactive'], true),
                fn ($query) => $query->where('is_active', $statusInput === 'active'))
            ->when(in_array($whatsAppInput, ['opted_in', 'opted_out'], true),
                fn ($query) => $query->where('whatsapp_opt_in', $whatsAppInput === 'opted_in'))
            ->when($this->periodStart($request), fn ($query, $from) => $query->whereDate('customers.created_at', '>=', $from))
            ->tap(fn ($query) => $this->applySort($query, $request))
            ->paginate(PerPage::resolve($request))
            ->withQueryString();

        return view('customers.index', [
            'customers' => $customers,
            'canManageStatus' => $canManageStatus,
            'filters' => [
                'search' => $search,
                'period' => $this->period($request),
                'status' => is_string($statusInput) ? $statusInput : '',
                'whatsapp' => is_string($whatsAppInput) ? $whatsAppInput : '',
                'sort' => $this->sort($request),
            ],
            // The real number of customers the business has, independent of any filter, so the
            // empty state can tell "no customers yet" apart from "none match this search".
            'totalCustomers' => Customer::query()
                ->when(! $canManageStatus, fn ($query) => $query->active())
                ->count(),
        ]);
    }

    /** The sort orders the list offers. `newest` matches the design's default. */
    private const SORTS = ['newest', 'oldest', 'name', 'spent'];

    private function sort(Request $request): string
    {
        $sort = $request->query('sort');

        return is_string($sort) && in_array($sort, self::SORTS, true) ? $sort : 'newest';
    }

    private function applySort($query, Request $request): void
    {
        match ($this->sort($request)) {
            'oldest' => $query->orderBy('customers.created_at')->orderBy('customers.id'),
            'name' => $query->orderBy('first_name')->orderBy('last_name'),
            // Ordered by the subquery alias, so the database does the sorting rather than PHP.
            'spent' => $query->orderByDesc('total_spent')->orderBy('customers.id'),
            default => $query->orderByDesc('customers.created_at')->orderByDesc('customers.id'),
        };
    }

    /** The periods the date filter offers, as a count of days back from today. */
    private const PERIODS = ['30' => 30, '90' => 90, '365' => 365];

    private function period(Request $request): string
    {
        $period = $request->query('period');

        return is_string($period) && array_key_exists($period, self::PERIODS) ? $period : 'all';
    }

    /**
     * The earliest registration date the filter admits, or null for "all time".
     *
     * Filters on when the customer was added, which is what a customer list's date control means —
     * a sales date filter belongs on the Sales screens.
     */
    private function periodStart(Request $request): ?string
    {
        $period = $this->period($request);

        return $period === 'all'
            ? null
            : CarbonImmutable::now(config('business.timezone'))->subDays(self::PERIODS[$period])->toDateString();
    }

    /**
     * The customer list as a CSV.
     *
     * Behind the same `viewAny` policy as the list, and carrying only what the Customer screens
     * already show — no internal notes, no address, nothing a viewer could not read on the page.
     * Written with `fputcsv`, so a name containing a comma or a quote is escaped rather than
     * breaking the row.
     */
    public function export(Request $request): StreamedResponse
    {
        Gate::authorize('viewAny', Customer::class);

        $filename = 'customers-'.CarbonImmutable::now(config('business.timezone'))->format('Y-m-d-His').'.csv';
        $canManageStatus = in_array($request->user()->role, [UserRole::Admin, UserRole::Manager], true);

        // The body streams after the controller returns, so it runs inside the request's Business
        // explicitly rather than relying on whatever context is live when the stream is consumed.
        $business = app(CurrentBusiness::class)->get();

        return response()->streamDownload(fn () => app(CurrentBusiness::class)->run($business, function () use ($canManageStatus): void {
            $handle = fopen('php://output', 'wb');
            fputcsv($handle, ['Code', 'Name', 'Phone', 'WhatsApp', 'Email', 'Tag', 'WhatsApp consent', 'Status']);

            Customer::query()
                ->when(! $canManageStatus, fn ($query) => $query->active())
                ->orderBy('first_name')->orderBy('last_name')
                // Chunked so a long customer list streams rather than being assembled in memory.
                ->chunk(200, function ($customers) use ($handle): void {
                    foreach ($customers as $customer) {
                        fputcsv($handle, [
                            $customer->customer_code,
                            $customer->full_name,
                            $customer->phone,
                            // The destination, stated plainly rather than as "Same".
                            $customer->effectiveWhatsAppPhone(),
                            $customer->email,
                            $customer->tag,
                            $customer->whatsapp_opt_in ? 'Opted in' : 'Not opted in',
                            $customer->is_active ? 'Active' : 'Archived',
                        ]);
                    }
                });

            fclose($handle);
        }), $filename, ['Content-Type' => 'text/csv']);
    }

    public function create(Request $request): View
    {
        Gate::authorize('create', Customer::class);

        // A name carried over from the navbar's "Add … as new customer" action. Flashed into the
        // old-input bag so the existing form picks it up through its normal `old()` path rather
        // than growing a second way to populate a field. Bounded and treated as plain text; the
        // form escapes it like any other value, and nothing here trusts it beyond display.
        $prefill = $request->query('name');

        if (is_string($prefill) && trim($prefill) !== '') {
            $request->session()->flashInput(['first_name' => mb_substr(trim($prefill), 0, 100)]);
        }

        return view('customers.create', [
            'canEditInternalDetails' => in_array(auth()->user()->role, [UserRole::Admin, UserRole::Manager], true),
            // Set only when the last submission was rejected for a phone already in use, so the
            // message can name who holds it and link to them.
            'duplicate' => $this->duplicateFor(request()),
        ]);
    }

    public function store(StoreCustomerRequest $request, CreateCustomer $action): RedirectResponse
    {
        try {
            $customer = $action->execute($request->user(), $request->validated());
        } catch (QueryException $exception) {
            $this->throwDuplicatePhone($exception);
        }

        return redirect()->route('customers.show', $customer)->with('status', 'Customer created successfully.');
    }

    /**
     * The customer profile.
     *
     * Three figures, a bounded purchase history and the real message history. Every number comes
     * from completed sales — the same scope the Customer Performance report uses — so the profile
     * and the report cannot disagree about what someone has spent.
     */
    public function show(Customer $customer): View
    {
        Gate::authorize('view', $customer);

        $sales = Sale::query()->where('customer_id', $customer->id)
            ->when(auth()->user()->role === UserRole::SalesRep, fn ($query) => $query->where('sold_by', auth()->id()));
        $saleIds = (clone $sales)->pluck('id');
        $recentPayments = SalePayment::query()->whereIn('sale_id', $saleIds)->with('sale:id,public_id,sale_number')
            ->latest('paid_at')->limit(5)->get();

        // Completed only: a voided sale is withdrawn and must not count toward what was spent.
        $completed = (clone $sales)->where('status', 'completed');

        return view('customers.show', [
            'customer' => $customer->load(['creator:id,name', 'updater:id,name']),
            'canViewInternalDetails' => Gate::allows('viewInternalDetails', $customer),
            'receivables' => [
                'sales_total' => bcadd((string) (clone $sales)->sum('total_amount'), '0', 2),
                'paid_total' => bcadd((string) (clone $sales)->sum('amount_paid'), '0', 2),
                'outstanding_total' => bcadd((string) (clone $sales)->sum('balance_due'), '0', 2),
                'open_count' => (clone $sales)->whereIn('payment_status', ['unpaid', 'partial'])->count(),
            ],
            'recentPayments' => $recentPayments,
            // The three headline figures. `sale_date` is the trading day, matching every other
            // sales figure in the application.
            'stats' => [
                'purchases' => (clone $completed)->count(),
                'spent' => bcadd((string) (clone $completed)->sum('total_amount'), '0', 2),
                'last_purchase' => (clone $completed)->max('sale_date'),
            ],
            // Bounded deliberately: a long-standing customer could have thousands of sales, and the
            // profile shows a recent history rather than the whole ledger. The Sales screens are
            // where the complete record lives.
            'purchases' => (clone $completed)
                ->with('items:id,sale_id,product_name_snapshot,quantity')
                ->latest('sale_date')->latest('id')->limit(10)->get(),
            // Real delivery records only. Nothing here is invented: if the application has never
            // messaged this customer, the panel says so.
            'messages' => $customer->whatsappDeliveries()
                ->latest('created_at')->limit(10)->get(),
        ]);
    }

    public function edit(Customer $customer): View
    {
        Gate::authorize('update', $customer);

        return view('customers.edit', [
            'customer' => $customer,
            'canUpdateIdentity' => Gate::allows('updateIdentity', $customer),
            'canEditInternalDetails' => Gate::allows('viewInternalDetails', $customer),
            'duplicate' => $this->duplicateFor(request(), $customer),
        ]);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer, UpdateCustomer $action): RedirectResponse
    {
        try {
            $action->execute($request->user(), $customer, $request->validated());
        } catch (QueryException $exception) {
            $this->throwDuplicatePhone($exception);
        }

        return redirect()->route('customers.show', $customer)->with('status', 'Customer updated.');
    }

    /**
     * Streams a customer photograph from the private disk to anyone allowed to view the customer.
     *
     * The file is never web-reachable directly, so this route is the only way to read it and the
     * policy check is the only gate — the same arrangement product photographs use.
     */
    public function photo(Request $request, Customer $customer, ImageStore $images): BinaryFileResponse
    {
        Gate::authorize('view', $customer);
        abort_unless($images->exists($customer->photo_path), 404);

        $response = response()->file($images->absolutePath($customer->photo_path), [
            'Content-Type' => $images->typeOf($customer->photo_path),
            'X-Content-Type-Options' => 'nosniff',
        ]);

        // Private, so a proxy never keeps a copy of an image only some users may see.
        $response->setPrivate();
        $response->setMaxAge(600);
        $response->headers->addCacheControlDirective('must-revalidate');

        return $response;
    }

    public function storePhoto(CustomerPhotoRequest $request, Customer $customer, SetCustomerPhoto $action): RedirectResponse
    {
        try {
            $action->store($request->user(), $customer, $request->file('photo'));
        } catch (RuntimeException) {
            // ImageStore rejected the decoded file even though the validator accepted it.
            throw ValidationException::withMessages(['photo' => 'The image could not be read or is not a supported type.']);
        }

        return back()->with('status', 'Customer photo saved.');
    }

    /** Removal is always explicit. A form submitted without a file leaves the photo alone. */
    public function destroyPhoto(Request $request, Customer $customer, SetCustomerPhoto $action): RedirectResponse
    {
        Gate::authorize('update', $customer);
        $action->remove($request->user(), $customer);

        return back()->with('status', 'Customer photo removed.');
    }

    public function activate(Request $request, Customer $customer, SetCustomerActiveState $action): RedirectResponse
    {
        Gate::authorize('changeStatus', $customer);
        abort_unless(! $customer->is_active, 403);
        $action->execute($request->user(), $customer, true);

        return back()->with('status', 'Customer activated.');
    }

    public function deactivate(Request $request, Customer $customer, SetCustomerActiveState $action): RedirectResponse
    {
        Gate::authorize('changeStatus', $customer);
        abort_unless($customer->is_active, 403);
        $action->execute($request->user(), $customer, false);

        return back()->with('status', 'Customer deactivated.');
    }

    public function consent(SetWhatsAppConsentRequest $request, Customer $customer, SetWhatsAppConsent $action): RedirectResponse
    {
        $action->execute($request->user(), $customer, $request->boolean('opt_in'));

        return back()->with('status', 'WhatsApp consent preference updated.');
    }

    public function activity(Request $request, Customer $customer): View
    {
        Gate::authorize('viewAudit', $customer);

        return view('customers.activity', [
            'customer' => $customer,
            'events' => AuditLog::query()
                ->where('auditable_type', $customer->getMorphClass())
                ->where('auditable_id', $customer->id)
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

    private function phoneSearchFragment(string $value): ?string
    {
        $digits = preg_replace('/\D/', '', $value);

        if ($digits === null || strlen($digits) < 4) {
            return null;
        }

        return str_starts_with($digits, '0') ? '234'.substr($digits, 1) : $digits;
    }

    /**
     * The customer who already holds the phone number a rejected submission tried to use.
     *
     * Looked up only from the operator's own old input, and only after validation has already
     * refused it, so this cannot be used to probe the customer list — it names the holder of a
     * number the operator has just typed themselves. Returns null unless there really is a clash.
     *
     * The number is canonicalised first, so `0802…`, `+234802…` and `234802…` all find the same
     * customer rather than appearing to be free.
     */
    private function duplicateFor(Request $request, ?Customer $customer = null): ?Customer
    {
        $phone = CanonicalLoginIdentifier::normalizeNigerianPhone((string) $request->old('phone'));

        if ($phone === null) {
            return null;
        }

        return Customer::query()
            ->where('phone', $phone)
            // On edit a customer's own number is not a duplicate of itself.
            ->when($customer, fn ($query) => $query->whereKeyNot($customer->getKey()))
            ->first();
    }

    private function throwDuplicatePhone(QueryException $exception): never
    {
        if (($exception->errorInfo[0] ?? null) !== '23000') {
            throw $exception;
        }

        throw ValidationException::withMessages(['phone' => 'A customer with this phone number already exists.']);
    }
}
