<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Product;
use App\Models\Sale;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $sales = Sale::query()->when($user->role === UserRole::SalesRep, fn ($query) => $query->where('sold_by', $user->id));
        $inventoryValue = null;

        if ($user->role !== UserRole::SalesRep) {
            $highPrecisionInventoryValue = (string) Product::query()
                ->selectRaw('COALESCE(SUM(current_stock * cost_price), 0) AS total')
                ->value('total');

            // Product values retain five fractional digits until the final aggregate is rounded once.
            $inventoryValue = Money::round($highPrecisionInventoryValue);
        }

        $salesToday = (string) (clone $sales)
            ->whereDate('created_at', today())
            ->selectRaw('COALESCE(SUM(total_amount), 0) AS total')
            ->value('total');

        return view('dashboard', [
            'user' => $user,
            'inventoryValue' => $inventoryValue,
            'salesToday' => Money::round($salesToday),
            'lowStockCount' => Product::query()->active()->whereColumn('current_stock', '<=', 'reorder_level')->count(),
            'lowStockProducts' => Product::query()->active()->whereColumn('current_stock', '<=', 'reorder_level')->orderBy('current_stock')->limit(4)->get(),
            'recentSales' => (clone $sales)->withCount('items')->latest()->limit(4)->get(),
        ]);
    }
}
