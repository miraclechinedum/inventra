<?php

namespace App\Http\Controllers\Inventory;

use App\Actions\Inventory\AdjustStock;
use App\Actions\Inventory\ArchiveProduct;
use App\Actions\Inventory\CreateProduct;
use App\Actions\Inventory\ForceDeleteProduct;
use App\Actions\Inventory\SetProductActiveState;
use App\Actions\Inventory\SetProductImage;
use App\Actions\Inventory\UpdateProduct;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\AdjustStockRequest;
use App\Http\Requests\Inventory\ProductImageRequest;
use App\Http\Requests\Inventory\StoreProductRequest;
use App\Http\Requests\Inventory\UpdateProductRequest;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Support\ImageStore;
use App\Support\PerPage;
use App\Support\ProductActivity;
use App\Support\ProductDeletionGuard;
use App\Support\TableSort;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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

    public function create(Request $request): View
    {
        Gate::authorize('create', Product::class);

        return view('inventory.products.create', [
            'categories' => $this->activeCategories(),
            // UI visibility only; CategoryController still authorizes every write itself.
            'canManageCategories' => $request->user()->can('create', ProductCategory::class),
        ]);
    }

    /**
     * Reports whether a SKU or product name is already taken, so the form can warn before the user
     * submits. Read-only and deliberately narrow: it answers about one value at a time and returns
     * only what the warning needs to render, never a searchable slice of the catalogue.
     *
     * This is a convenience. The unique rules on the request remain the only thing that decides
     * whether a product may be saved.
     */
    public function checkDuplicate(Request $request): JsonResponse
    {
        Gate::authorize('create', Product::class);

        $field = $request->query('field');
        $value = $request->query('value');
        $ignore = $request->query('ignore');

        if (! in_array($field, ['sku', 'name'], true) || ! is_string($value)) {
            return response()->json(['exists' => false]);
        }

        $value = trim($value);

        if ($value === '' || mb_strlen($value) > 255) {
            return response()->json(['exists' => false]);
        }

        $match = Product::query()
            ->when($field === 'sku', fn ($query) => $query->where('sku', mb_strtoupper($value)))
            ->when($field === 'name', fn ($query) => $query->where('name', $value))
            ->when(is_string($ignore) && ctype_digit($ignore), fn ($query) => $query->whereKeyNot((int) $ignore))
            ->first(['id', 'public_id', 'name', 'sku']);

        if ($match === null) {
            return response()->json(['exists' => false]);
        }

        return response()->json([
            'exists' => true,
            'name' => $match->name,
            'sku' => $match->sku,
            'url' => route('inventory.products.show', $match),
        ]);
    }

    public function store(StoreProductRequest $request, CreateProduct $action): RedirectResponse
    {
        $validated = $request->validated();
        unset($validated['image']);

        try {
            $product = $action->execute($request->user(), $validated, $request->file('image'));
        } catch (QueryException $exception) {
            $this->throwDuplicateValidation($exception);
        } catch (RuntimeException) {
            // ImageStore rejected the decoded file even though the validator accepted it. Nothing
            // was written, so the product was not created either.
            throw ValidationException::withMessages(['image' => 'The image could not be read or is not a supported type.']);
        }

        // Both paths redirect, so a refresh re-issues a GET and cannot create a second product.
        if ($request->boolean('save_and_add_another')) {
            return redirect()
                ->route('inventory.products.create')
                ->with('status', $product->name.' created. Add the next product.');
        }

        return redirect()->route('inventory.index')->with('status', $product->name.' added to inventory.');
    }

    public function show(Request $request, Product $product): View
    {
        Gate::authorize('view', $product);
        $canViewMovements = $request->user()->can('viewAny', InventoryMovement::class);

        return view('inventory.products.show', [
            'product' => $product->load(['category:id,name', 'creator:id,name', 'updater:id,name']),
            'costPrice' => $request->user()->can('viewCost', $product) ? $product->cost_price : null,
            'recentMovements' => $canViewMovements
                ? $product->movements()->with('performer:id,name')->latest('created_at')->latest('id')->limit(12)->get()
                : collect(),
            'canViewMovements' => $canViewMovements,
            'unitsSold' => ProductActivity::unitsSold($product),
            'soldWindowDays' => ProductActivity::SOLD_WINDOW_DAYS,
            'canManageCategories' => $request->user()->can('create', ProductCategory::class),
            'deletionRefusal' => app(ProductDeletionGuard::class)->refusalReason($product),
            'categories' => $this->activeCategories(),
        ]);
    }

    public function edit(Request $request, Product $product): View
    {
        Gate::authorize('update', $product);

        return view('inventory.products.edit', [
            'product' => $product,
            'categories' => $this->activeCategories(),
            // UI visibility only; CategoryController still authorizes every write itself.
            'canManageCategories' => $request->user()->can('create', ProductCategory::class),
            // Explains why permanent deletion is unavailable; the route re-checks regardless.
            'deletionRefusal' => app(ProductDeletionGuard::class)->refusalReason($product),
        ]);
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

    public function storeImage(ProductImageRequest $request, Product $product, SetProductImage $action): RedirectResponse
    {
        try {
            $action->store($request->user(), $product, $request->file('image'));
        } catch (RuntimeException) {
            // ImageStore rejected the decoded file even though the validator accepted it.
            throw ValidationException::withMessages(['image' => 'The image could not be read or is not a supported type.']);
        }

        return back()->with('status', 'Product image saved.');
    }

    public function destroyImage(Request $request, Product $product, SetProductImage $action): RedirectResponse
    {
        Gate::authorize('update', $product);
        $action->remove($request->user(), $product);

        return back()->with('status', 'Product image removed.');
    }

    /**
     * Streams a product photograph from the private disk to anyone allowed to view the product.
     * The file is never web-reachable directly, so this route is the only way to read it and the
     * policy check is the only gate. No storage:link and no signed URL are involved, which is what
     * lets an ordinary <img src> work on shared hosting.
     */
    public function image(Request $request, Product $product, ImageStore $images): BinaryFileResponse
    {
        Gate::authorize('view', $product);
        abort_unless($images->exists($product->image_path), 404);

        $response = response()->file($images->absolutePath($product->image_path), [
            'Content-Type' => $images->typeOf($product->image_path),
            'X-Content-Type-Options' => 'nosniff',
        ]);

        // setPrivate() rather than a Cache-Control string: Symfony rewrites a hand-written header
        // that carries max-age without an explicit directive into `public`, which would invite a
        // proxy to keep a copy of an image only some users are allowed to see.
        $response->setPrivate();
        $response->setMaxAge(600);
        $response->headers->addCacheControlDirective('must-revalidate');

        return $response;
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
        Gate::authorize('archive', $product);
        $action->execute($request->user(), $product);

        return redirect()->route('inventory.index')->with('status', $product->name.' archived. Existing records are unchanged.');
    }

    public function reactivate(Request $request, Product $product, ArchiveProduct $action): RedirectResponse
    {
        Gate::authorize('reactivate', $product);
        $action->reactivate($request->user(), $product);

        return back()->with('status', $product->name.' reactivated.');
    }

    /**
     * Permanent deletion, allowed only for an Administrator and only for a product no business
     * record refers to. The guard is re-run here rather than trusted from the page that offered the
     * action, so a stale or forged request cannot remove something that gained history meanwhile.
     */
    public function forceDestroy(Request $request, Product $product, ForceDeleteProduct $action, ProductDeletionGuard $guard): RedirectResponse
    {
        Gate::authorize('delete', $product);

        $refusal = $guard->refusalReason($product);

        if ($refusal !== null) {
            throw ValidationException::withMessages(['product' => $refusal]);
        }

        $action->execute($request->user(), $product);

        return redirect()->route('inventory.index')->with('status', 'Product permanently deleted.');
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
