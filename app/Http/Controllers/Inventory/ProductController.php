<?php

namespace App\Http\Controllers\Inventory;

use App\Actions\Inventory\AdjustStock;
use App\Actions\Inventory\ArchiveProduct;
use App\Actions\Inventory\CreateProduct;
use App\Actions\Inventory\SetProductActiveState;
use App\Actions\Inventory\UpdateProduct;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\AdjustStockRequest;
use App\Http\Requests\Inventory\StoreProductRequest;
use App\Http\Requests\Inventory\UpdateProductRequest;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Support\PerPage;
use App\Support\TableSort;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProductController extends Controller
{
    /** Sort keys the product list exposes, mapped to the real columns they may order by. */
    private const SORTABLE = [
        'sku' => 'sku',
        'name' => 'name',
        'price' => 'selling_price',
        'stock' => 'current_stock',
        'reorder' => 'reorder_level',
        'status' => 'is_active',
    ];

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Product::class);
        $searchInput = $request->query('search');
        $categoryInput = $request->query('category');
        $stockInput = $request->query('stock');
        $statusInput = $request->query('status');
        $search = is_string($searchInput) ? trim($searchInput) : '';
        $escaped = $this->escapeLike($search);
        $canManage = in_array($request->user()->role, [UserRole::Admin, UserRole::Manager], true);
        $sort = TableSort::resolve($request, self::SORTABLE, 'name');

        $products = Product::query()
            ->with('category:id,name')
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', $escaped.'%')
                ->orWhere('sku', 'like', mb_strtoupper($escaped).'%')))
            ->when(is_string($categoryInput) && ctype_digit($categoryInput), fn ($query) => $query->where('category_id', $categoryInput))
            ->when($stockInput === 'low', fn ($query) => $query->lowStock())
            ->when($stockInput === 'ok', fn ($query) => $query->whereColumn('current_stock', '>', 'reorder_level'))
            ->when(! $canManage, fn ($query) => $query->active())
            ->when($canManage && in_array($statusInput, ['active', 'inactive'], true),
                fn ($query) => $query->where('is_active', $statusInput === 'active'))
            ->orderBy($sort['columns'][0], $sort['direction'])
            ->orderBy('id')
            ->paginate(PerPage::resolve($request))
            ->withQueryString();

        return view('inventory.index', [
            'products' => $products,
            'sort' => $sort,
            'categories' => ProductCategory::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'canManage' => $canManage,
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', Product::class);

        return view('inventory.products.create', ['categories' => $this->activeCategories()]);
    }

    public function store(StoreProductRequest $request, CreateProduct $action): RedirectResponse
    {
        try {
            $product = $action->execute($request->user(), $request->validated());
        } catch (QueryException $exception) {
            $this->throwDuplicateValidation($exception);
        }

        return redirect()->route('inventory.products.show', $product)->with('status', 'Product created successfully.');
    }

    public function show(Request $request, Product $product): View
    {
        Gate::authorize('view', $product);
        $canViewMovements = $request->user()->can('viewAny', InventoryMovement::class);

        return view('inventory.products.show', [
            'product' => $product->load(['category:id,name', 'creator:id,name', 'updater:id,name']),
            'costPrice' => $request->user()->can('viewCost', $product) ? $product->cost_price : null,
            'recentMovements' => $canViewMovements
                ? $product->movements()->with('performer:id,name')->latest('created_at')->limit(8)->get()
                : collect(),
            'canViewMovements' => $canViewMovements,
        ]);
    }

    public function edit(Product $product): View
    {
        Gate::authorize('update', $product);

        return view('inventory.products.edit', ['product' => $product, 'categories' => $this->activeCategories()]);
    }

    public function update(UpdateProductRequest $request, Product $product, UpdateProduct $action): RedirectResponse
    {
        try {
            $action->execute($request->user(), $product, $request->validated());
        } catch (QueryException $exception) {
            $this->throwDuplicateValidation($exception);
        }

        return redirect()->route('inventory.products.show', $product)->with('status', 'Product updated.');
    }

    public function adjust(AdjustStockRequest $request, Product $product, AdjustStock $action): RedirectResponse
    {
        $action->execute($request->user(), $product, $request->validated());

        return back()->with('status', 'Stock adjusted successfully.');
    }

    public function activate(Request $request, Product $product, SetProductActiveState $action): RedirectResponse
    {
        Gate::authorize('update', $product);
        abort_unless(! $product->is_active, 403);
        $action->execute($request->user(), $product, true);

        return back()->with('status', 'Product activated.');
    }

    public function deactivate(Request $request, Product $product, SetProductActiveState $action): RedirectResponse
    {
        Gate::authorize('update', $product);
        abort_unless($product->is_active, 403);
        $action->execute($request->user(), $product, false);

        return back()->with('status', 'Product deactivated.');
    }

    public function destroy(Request $request, Product $product, ArchiveProduct $action): RedirectResponse
    {
        Gate::authorize('delete', $product);
        $action->execute($request->user(), $product);

        return redirect()->route('inventory.index')->with('status', 'Product archived.');
    }

    public function movements(Request $request, Product $product): View
    {
        Gate::authorize('viewAny', InventoryMovement::class);
        Gate::authorize('view', $product);

        return view('inventory.products.movements', [
            'product' => $product,
            'movements' => $product->movements()->with('performer:id,name')->latest('created_at')->latest('id')->paginate(PerPage::resolve($request))->withQueryString(),
        ]);
    }

    private function activeCategories()
    {
        return ProductCategory::query()->where('is_active', true)->orderBy('name')->get();
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function throwDuplicateValidation(QueryException $exception): never
    {
        if (($exception->errorInfo[0] ?? null) !== '23000') {
            throw $exception;
        }

        throw ValidationException::withMessages(['sku' => 'That SKU is already in use.']);
    }
}
