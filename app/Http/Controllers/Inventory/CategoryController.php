<?php

namespace App\Http\Controllers\Inventory;

use App\Actions\Inventory\CreateCategory;
use App\Actions\Inventory\SetCategoryActiveState;
use App\Actions\Inventory\UpdateCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreCategoryRequest;
use App\Http\Requests\Inventory\UpdateCategoryRequest;
use App\Models\ProductCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class CategoryController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', ProductCategory::class);

        return view('inventory.categories.index', [
            'categories' => ProductCategory::query()->withCount('products')->orderBy('name')->paginate(20),
        ]);
    }

    public function store(StoreCategoryRequest $request, CreateCategory $action): RedirectResponse
    {
        $action->execute($request->user(), $request->validated());

        return back()->with('status', 'Category created.');
    }

    public function update(UpdateCategoryRequest $request, ProductCategory $category, UpdateCategory $action): RedirectResponse
    {
        $action->execute($request->user(), $category, $request->validated());

        return back()->with('status', 'Category updated.');
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
