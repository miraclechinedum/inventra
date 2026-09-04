<?php

namespace App\Http\Controllers;

use App\Actions\Customer\CreateCustomer;
use App\Actions\Customer\SetCustomerActiveState;
use App\Actions\Customer\SetWhatsAppConsent;
use App\Actions\Customer\UpdateCustomer;
use App\Enums\UserRole;
use App\Http\Requests\Customer\SetWhatsAppConsentRequest;
use App\Http\Requests\Customer\StoreCustomerRequest;
use App\Http\Requests\Customer\UpdateCustomerRequest;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Support\CanonicalLoginIdentifier;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CustomerController extends Controller
{
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

        $customers = Customer::query()
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('first_name', 'like', $escaped.'%')
                ->orWhere('last_name', 'like', $escaped.'%')
                ->orWhereRaw("CONCAT(first_name, ' ', COALESCE(last_name, '')) LIKE ?", [$escaped.'%'])
                ->orWhere('customer_code', 'like', mb_strtoupper($escaped).'%')
                ->orWhere('email', 'like', mb_strtolower($escaped).'%')
                ->when($phone, fn ($query) => $query->orWhere('phone', 'like', $phone.'%'))
                ->when($phoneFragment, fn ($query) => $query->orWhereRaw("REPLACE(phone, '+', '') LIKE ?", ['%'.$phoneFragment.'%']))))
            ->when(! $canManageStatus, fn ($query) => $query->active())
            ->when($canManageStatus && in_array($statusInput, ['active', 'inactive'], true),
                fn ($query) => $query->where('is_active', $statusInput === 'active'))
            ->when(in_array($whatsAppInput, ['opted_in', 'opted_out'], true),
                fn ($query) => $query->where('whatsapp_opt_in', $whatsAppInput === 'opted_in'))
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->paginate(15)
            ->withQueryString();

        return view('customers.index', compact('customers', 'canManageStatus'));
    }

    public function create(): View
    {
        Gate::authorize('create', Customer::class);

        return view('customers.create', [
            'canEditInternalDetails' => in_array(auth()->user()->role, [UserRole::Admin, UserRole::Manager], true),
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

    public function show(Customer $customer): View
    {
        Gate::authorize('view', $customer);

        $sales = Sale::query()->where('customer_id', $customer->id)
            ->when(auth()->user()->role === UserRole::SalesRep, fn ($query) => $query->where('sold_by', auth()->id()));
        $saleIds = (clone $sales)->pluck('id');
        $recentPayments = SalePayment::query()->whereIn('sale_id', $saleIds)->with('sale:id,sale_number')
            ->latest('paid_at')->limit(5)->get();

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
        ]);
    }

    public function edit(Customer $customer): View
    {
        Gate::authorize('update', $customer);

        return view('customers.edit', [
            'customer' => $customer,
            'canUpdateIdentity' => Gate::allows('updateIdentity', $customer),
            'canEditInternalDetails' => Gate::allows('viewInternalDetails', $customer),
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

    public function activity(Customer $customer): View
    {
        Gate::authorize('viewAudit', $customer);

        return view('customers.activity', [
            'customer' => $customer,
            'events' => AuditLog::query()
                ->where('auditable_type', $customer->getMorphClass())
                ->where('auditable_id', $customer->id)
                ->with('actor:id,name')
                ->latest('created_at')
                ->paginate(20),
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

    private function throwDuplicatePhone(QueryException $exception): never
    {
        if (($exception->errorInfo[0] ?? null) !== '23000') {
            throw $exception;
        }

        throw ValidationException::withMessages(['phone' => 'A customer with this phone number already exists.']);
    }
}
