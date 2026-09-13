<?php

namespace App\Http\Controllers\Inventory;

use App\Actions\Inventory\CreateCategory;
use App\Actions\Inventory\SetCategoryActiveState;
use App\Actions\Inventory\UpdateCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreCategoryRequest;
use App\Http\Requests\Inventory\UpdateCategoryRequest;
use App\Models\ProductCategory;
use App\Support\PerPage;
use App\Support\TableSort;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class CategoryController extends Controller
{
    /** Sort keys this listing exposes, mapped to the columns they may order by. */
    private const SORTABLE = [
        'name' => 'name',
        'products' => 'products_count',
        'status' => 'is_active',
    ];

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', ProductCategory::class);
        $searchInput = $request->query('search');
        $statusInput = $request->query('status');
        $search = is_string($searchInput) ? trim($searchInput) : '';
        $sort = TableSort::resolve($request, self::SORTABLE, 'name');

        $categories = ProductCategory::query()
            ->withCount('products')
            ->when($search !== '', fn ($query) => $query->where('name', 'like', $this->escapeLike($search).'%'))
            ->when(in_array($statusInput, ['active', 'inactive'], true),
                fn ($query) => $query->where('is_active', $statusInput === 'active'))
            ->orderBy($sort['columns'][0], $sort['direction'])
            ->orderBy('id')
            ->paginate(PerPage::resolve($request))
            ->withQueryString();

        return view('inventory.categories.index', [
            'categories' => $categories,
            'sort' => $sort,
            'search' => $search,
            'status' => is_string($statusInput) ? $statusInput : '',
            'canManage' => $request->user()->can('create', ProductCategory::class),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', ProductCategory::class);

        return view('inventory.categories.create');
    }

    public function store(StoreCategoryRequest $request, CreateCategory $action): RedirectResponse
    {
        $category = $action->execute($request->user(), $request->validated());

        // Redirecting rather than rendering means a refresh cannot create a second category.
        if ($request->boolean('save_and_add_another')) {
            return redirect()
                ->route('inventory.categories.create')
                ->with('status', $category->name.' created. Add the next category.');
        }

        return redirect()->route('inventory.categories.index')->with('status', 'Category created.');
    }

    /**
     * Creates a category from the product form's combobox and returns it as JSON.
     *
     * Authorization and validation are the shared request class's, so this cannot accept anything
     * the categories screen would reject. A name that already exists — in any casing, once trimmed
     * — fails the unique rule rather than producing a second category, and the response carries
     * only the id and name the combobox needs.
     */
    public function storeQuick(StoreCategoryRequest $request, CreateCategory $action): JsonResponse
    {
        try {
            $category = $action->execute($request->user(), $request->validated());
        } catch (QueryException $exception) {
            // Two requests raced past the unique rule; the database index settled it. Hand back the
            // category that won so the browser selects it instead of reporting a failure.
            if (($exception->errorInfo[0] ?? null) !== '23000') {
                throw $exception;
            }

            $existing = ProductCategory::query()->where('name', $request->validated()['name'])->first();

            if ($existing === null) {
                throw $exception;
            }

            return response()->json(['id' => $existing->id, 'name' => $existing->name, 'created' => false]);
        }

        return response()->json(['id' => $category->id, 'name' => $category->name, 'created' => true], 201);
    }

    public function edit(ProductCategory $category): View
    {
        Gate::authorize('update', $category);

        return view('inventory.categories.edit', ['category' => $category]);
    }

    public function update(UpdateCategoryRequest $request, ProductCategory $category, UpdateCategory $action): RedirectResponse
    {
        $action->execute($request->user(), $category, $request->validated());

        return redirect()->route('inventory.categories.index')->with('status', 'Category updated.');
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    public function activate(Request $request, ProductCategory $category, SetCategoryActiveState $action): RedirectResponse
    {
        Gate::authorize('changeStatus', $category);
        $action->execute($request->user(), $category, true);

        return back()->with('status', 'Category activated.');
    }

    public function deactivate(Request $request, ProductCategory $category, SetCategoryActiveState $action): RedirectResponse
    {
        Gate::authorize('changeStatus', $category);
        $action->execute($request->user(), $category, false);

        return back()->with('status', 'Category deactivated.');
    }
}
