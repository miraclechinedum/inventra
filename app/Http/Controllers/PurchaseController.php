<?php

namespace App\Http\Controllers;

use App\Actions\Purchase\IssuePurchaseRequest;
use App\Actions\Purchase\ReceivePurchase;
use App\Http\Requests\ReceivePurchaseRequest;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class PurchaseController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Purchase::class);
        $search = is_string($request->query('search')) ? trim($request->query('search')) : '';
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
        $from = is_string($request->query('from')) ? $request->query('from') : null;
        $to = is_string($request->query('to')) ? $request->query('to') : null;
        $purchases = Purchase::query()->withCount('items')->when($search !== '', fn ($query) => $query
            ->where(fn ($query) => $query->where('purchase_number', 'like', mb_strtoupper($escaped).'%')
                ->orWhere('supplier_name_snapshot', 'like', $escaped.'%')
                ->orWhere('supplier_code_snapshot', 'like', mb_strtoupper($escaped).'%')
                ->orWhere('reference_number', 'like', $escaped.'%')
                ->orWhere('received_by_name_snapshot', 'like', $escaped.'%')))
            ->when($from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from), fn ($query) => $query->whereDate('received_at', '>=', $from))
            ->when($to && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to), fn ($query) => $query->whereDate('received_at', '<=', $to))
            ->latest('received_at')->paginate(15)->withQueryString();

        return view('purchases.index', compact('purchases', 'search', 'from', 'to'));
    }

    public function create(Request $request, IssuePurchaseRequest $tokens): View
    {
        Gate::authorize('create', Purchase::class);

        return view('purchases.create', [
            'suppliers' => Supplier::query()->where('is_active', true)->orderBy('name')->get(['id', 'supplier_code', 'name']),
            'products' => Product::query()->where('is_active', true)->orderBy('name')->get(['id', 'sku', 'name', 'unit']),
            'requestToken' => $tokens->execute($request->user(), $request->session()),
        ]);
    }

    public function store(ReceivePurchaseRequest $request, ReceivePurchase $action): RedirectResponse
    {
        $purchase = $action->execute($request->user(), $request->validated(), $request->session()->getId());

        return redirect()->route('purchases.show', $purchase)->with('status', 'Purchase received and stock updated.');
    }

    public function show(Purchase $purchase): View
    {
        Gate::authorize('view', $purchase);

        return view('purchases.show', ['purchase' => $purchase->load('items')]);
    }

    public function receipt(Purchase $purchase): View
    {
        Gate::authorize('view', $purchase);

        return view('purchases.receipt', ['purchase' => $purchase->load('items')]);
    }
}
