<?php

namespace App\Http\Controllers;

use App\Actions\Supplier\CreateSupplier;
use App\Actions\Supplier\SetSupplierActive;
use App\Actions\Supplier\UpdateSupplier;
use App\Http\Requests\SupplierRequest;
use App\Models\Supplier;
use App\Support\PerPage;
use App\Support\TableSort;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class SupplierController extends Controller
{
    /** Sort keys the supplier list exposes, mapped to the real columns they may order by. */
    private const SORTABLE = [
        'name' => 'name',
        'code' => 'supplier_code',
        'contact' => 'contact_person',
        'purchases' => 'purchases_count',
        'status' => 'is_active',
    ];

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Supplier::class);
        $search = is_string($request->query('search')) ? trim($request->query('search')) : '';
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
        $status = $request->query('status');
        $sort = TableSort::resolve($request, self::SORTABLE, 'name');

        $suppliers = Supplier::query()
            ->withCount('purchases')
            ->withMax('purchases', 'received_at')
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', $escaped.'%')
                ->orWhere('supplier_code', 'like', mb_strtoupper($escaped).'%')
                ->orWhere('contact_person', 'like', $escaped.'%')
                ->orWhere('phone', 'like', $escaped.'%')
                ->orWhere('email', 'like', mb_strtolower($escaped).'%')))
            ->when(in_array($status, ['active', 'inactive'], true), fn ($query) => $query->where('is_active', $status === 'active'))
            ->orderBy($sort['columns'][0], $sort['direction'])->orderBy('id')
            ->paginate(PerPage::resolve($request))->withQueryString();

        $statusFilter = is_string($status) ? $status : '';

        return view('suppliers.index', compact('suppliers', 'search', 'statusFilter', 'sort'));
    }

    public function create(): View
    {
        Gate::authorize('create', Supplier::class);

        return view('suppliers.create');
    }

    public function store(SupplierRequest $request, CreateSupplier $action): RedirectResponse
    {
        $supplier = $action->execute($request->user(), $request->validated());

        return redirect()->route('suppliers.show', $supplier)->with('status', 'Supplier created.');
    }

    public function show(Request $request, Supplier $supplier): View
    {
        Gate::authorize('view', $supplier);

        return view('suppliers.show', [
            'supplier' => $supplier->loadCount('purchases')->loadMax('purchases', 'received_at'),
            'purchases' => $supplier->purchases()->latest('received_at')->latest('id')->paginate(PerPage::resolve($request))->withQueryString(),
        ]);
    }

    public function edit(Supplier $supplier): View
    {
        Gate::authorize('update', $supplier);

        return view('suppliers.edit', compact('supplier'));
    }

    public function update(SupplierRequest $request, Supplier $supplier, UpdateSupplier $action): RedirectResponse
    {
        $action->execute($request->user(), $supplier, $request->validated());

        return redirect()->route('suppliers.show', $supplier)->with('status', 'Supplier updated.');
    }

    public function activate(Request $request, Supplier $supplier, SetSupplierActive $action): RedirectResponse
    {
        Gate::authorize('changeStatus', $supplier);
        $action->execute($request->user(), $supplier, true);

        return back()->with('status', 'Supplier activated.');
    }

    public function deactivate(Request $request, Supplier $supplier, SetSupplierActive $action): RedirectResponse
    {
        Gate::authorize('changeStatus', $supplier);
        $action->execute($request->user(), $supplier, false);

        return back()->with('status', 'Supplier deactivated.');
    }
}
