<?php

namespace App\Http\Controllers;

use App\Actions\Sale\CreateSale;
use App\Actions\Sale\VoidSale;
use App\Contracts\WhatsAppClient;
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
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SaleController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Sale::class);
        $searchInput = $request->query('search');
        $statusInput = $request->query('status');
        $methodInput = $request->query('payment_method');
        $paymentInput = $request->query('payment_status');
        $sellerInput = $request->query('sold_by');
        $fromInput = $request->query('from');
        $toInput = $request->query('to');
        $search = is_string($searchInput) ? trim($searchInput) : '';
        $escaped = $this->escapeLike($search);
        $salesRep = $request->user()->role === UserRole::SalesRep;

        $sales = Sale::query()
            ->withCount('items')
            ->when($salesRep, fn ($query) => $query->where('sold_by', $request->user()->id))
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('sale_number', 'like', mb_strtoupper($escaped).'%')
                ->orWhere('customer_name_snapshot', 'like', $escaped.'%')
                ->orWhere('customer_code_snapshot', 'like', mb_strtoupper($escaped).'%')
                ->orWhere('customer_phone_snapshot', 'like', $escaped.'%')))
            ->when(is_string($statusInput) && in_array($statusInput, array_column(SaleStatus::cases(), 'value'), true), fn ($query) => $query->where('status', $statusInput))
            ->when(is_string($methodInput) && in_array($methodInput, array_column(PaymentMethod::cases(), 'value'), true), fn ($query) => $query->where('payment_method', $methodInput))
            ->when(is_string($paymentInput) && in_array($paymentInput, array_column(PaymentStatus::cases(), 'value'), true), fn ($query) => $query->where('payment_status', $paymentInput))
            ->when(! $salesRep && is_string($sellerInput) && ctype_digit($sellerInput), fn ($query) => $query->where('sold_by', $sellerInput))
            ->when(is_string($fromInput) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromInput), fn ($query) => $query->whereDate('created_at', '>=', $fromInput))
            ->when(is_string($toInput) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $toInput), fn ($query) => $query->whereDate('created_at', '<=', $toInput))
            ->latest('created_at')
            ->paginate(20)
            ->withQueryString();

        return view('sales.index', [
            'sales' => $sales,
            'sellers' => $salesRep ? collect() : User::query()->orderBy('name')->get(['id', 'name']),
            'salesRep' => $salesRep,
        ]);
    }

    public function create(Request $request): View
    {
        Gate::authorize('create', Sale::class);
        $searchInput = $request->query('product_search');
        $search = is_string($searchInput) ? trim($searchInput) : '';
        $escaped = $this->escapeLike($search);

        return view('sales.create', [
            'customers' => Customer::query()->active()->orderBy('first_name')->get(['id', 'customer_code', 'first_name', 'last_name', 'phone']),
            'products' => Product::query()
                ->active()
                ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                    ->where('sku', 'like', mb_strtoupper($escaped).'%')
                    ->orWhere('name', 'like', $escaped.'%')))
                ->orderBy('name')
                ->limit(100)
                ->get(['id', 'sku', 'name', 'unit', 'selling_price', 'current_stock']),
            'productSearch' => $search,
        ]);
    }

    public function store(StoreSaleRequest $request, CreateSale $action): RedirectResponse
    {
        $sale = $action->execute($request->user(), $request->validated());

        return redirect()->route('sales.show', $sale)->with('status', 'Sale recorded successfully.');
    }

    public function show(Request $request, Sale $sale, WhatsAppClient $client): View
    {
        Gate::authorize('view', $sale);
        $sale->load(['items', 'voider:id,name', 'customer:id,phone,is_active,whatsapp_opt_in,whatsapp_opt_in_at,whatsapp_opt_out_at']);
        $sendToken = (string) Str::uuid();
        $request->session()->put('whatsapp.send.'.$sale->id, $sendToken);

        return view('sales.show', [
            'sale' => $sale,
            'whatsappDeliveries' => $sale->whatsappDeliveries()->latest()->limit(5)->get(),
            'whatsappConfigured' => $client->isConfigured(),
            'whatsappEligible' => $sale->customer->is_active && $sale->customer->whatsapp_opt_in
                && $sale->customer->whatsapp_opt_in_at !== null && $sale->customer->whatsapp_opt_out_at === null,
            'whatsappSendToken' => $sendToken,
        ]);
    }

    public function receipt(Sale $sale): View
    {
        Gate::authorize('view', $sale);

        return view('sales.receipt', ['sale' => $sale->load('items')]);
    }

    public function void(VoidSaleRequest $request, Sale $sale, VoidSale $action): RedirectResponse
    {
        $action->execute($request->user(), $sale, $request->validated('reason'));

        return back()->with('status', 'Sale voided and stock restored.');
    }

    public function activity(Sale $sale): View
    {
        Gate::authorize('viewAudit', $sale);

        return view('sales.activity', [
            'sale' => $sale,
            'events' => AuditLog::query()
                ->where('auditable_type', $sale->getMorphClass())
                ->where('auditable_id', $sale->id)
                ->with('actor:id,name')
                ->latest('created_at')
                ->paginate(20),
        ]);
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
