<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The navbar type-ahead: customers and products in one response.
 *
 * Every result is shaped by hand rather than serialised from a model, so the payload carries only
 * what the dropdown draws. That matters twice over: a product's `cost_price` is governed by
 * ProductPolicy::viewCost and must not leak through a search box, and a customer's address, notes
 * and consent history have no business in a suggestion list.
 *
 * Each half is gated independently by the policy that owns it, so a Sales Representative who may
 * look up a customer but not browse the catalogue gets exactly the half they are entitled to
 * rather than an empty response or a 403 on the whole box.
 *
 * The search term reaches the database only through a bound parameter with LIKE wildcards escaped,
 * and is reflected back to the caller verbatim so the view — not this endpoint — decides how to
 * escape it for display.
 */
class GlobalSearchController extends Controller
{
    /** Below this a query matches most of the table; above it the index prefix does the work. */
    private const MIN_LENGTH = 2;

    private const LIMIT = 5;

    /** The configured business currency symbol, so prices read as money rather than bare numbers. */
    private string $currency;

    public function __construct(\App\Settings\BusinessSettings $settings)
    {
        $this->currency = $settings->currencySymbol();
    }

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $user = $request->user();
        $term = trim((string) ($validated['q'] ?? ''));

        if (mb_strlen($term) < self::MIN_LENGTH) {
            return response()->json([
                'query' => $term,
                'customers' => [],
                'products' => [],
                'searched' => [],
                'canCreateCustomer' => false,
            ]);
        }

        $escaped = $this->escapeLike($term);
        $searched = [];
        $customers = [];
        $products = [];

        if ($user->can('viewAny', Customer::class)) {
            $searched[] = 'customers';
            $customers = $this->customers($escaped);
        }

        if ($user->can('viewAny', Product::class)) {
            $searched[] = 'products';
            $products = $this->products($escaped);
        }

        return response()->json([
            'query' => $term,
            'customers' => $customers,
            'products' => $products,
            // What was actually looked in, so the no-results copy can say so truthfully rather
            // than claiming only products were searched when both were.
            'searched' => $searched,
            'canCreateCustomer' => $user->can('create', Customer::class),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function customers(string $escaped): array
    {
        return Customer::query()
            ->active()
            ->where(fn ($query) => $query
                ->where('first_name', 'like', $escaped.'%')
                ->orWhere('last_name', 'like', $escaped.'%')
                ->orWhere('phone', 'like', $escaped.'%')
                ->orWhere('customer_code', 'like', mb_strtoupper($escaped).'%'))
            ->orderBy('first_name')->orderBy('id')
            ->limit(self::LIMIT)
            ->get(['id', 'first_name', 'last_name', 'phone'])
            ->map(fn (Customer $customer): array => [
                'name' => $customer->full_name,
                'phone' => $customer->phone,
                'initials' => $this->initials($customer->full_name),
                'url' => route('customers.show', $customer),
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function products(string $escaped): array
    {
        return Product::query()
            ->active()
            ->where(fn ($query) => $query
                // Product names are multi-word ("Camry oil filter"), and an operator searching
                // "filter" means the word wherever it falls. A prefix match would return nothing
                // for exactly the case the design shows. The SKU stays a prefix match: codes are
                // read and typed from the start, and an infix scan over them buys nothing.
                ->where('name', 'like', '%'.$escaped.'%')
                ->orWhere('sku', 'like', mb_strtoupper($escaped).'%'))
            ->orderBy('name')->orderBy('id')
            ->limit(self::LIMIT)
            // `cost_price` is deliberately absent: it is policy-gated elsewhere and the dropdown
            // shows the selling price.
            ->get(['id', 'public_id', 'name', 'sku', 'selling_price', 'current_stock', 'unit'])
            ->map(fn (Product $product): array => [
                'name' => $product->name,
                'sku' => $product->sku,
                'price' => $this->currency.\App\Support\Money::compact((string) $product->selling_price),
                'stock' => \App\Support\Quantity::trim((string) $product->current_stock),
                'inStock' => bccomp((string) $product->current_stock, '0', 3) > 0,
                'url' => route('inventory.products.show', $product),
            ])
            ->all();
    }

    private function initials(string $name): string
    {
        return collect(explode(' ', $name))
            ->filter()
            ->take(2)
            ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
            ->implode('');
    }

    /** Neutralises LIKE's own wildcards so a typed % or _ searches for that character. */
    private function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);
    }
}
