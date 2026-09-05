<?php

namespace App\Reports;

use App\Enums\PaymentMethod;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class BusinessReports
{
    public function sales(ReportFilters $filters): array
    {
        $base = Sale::query()->whereBetween('created_at', [$filters->utcStart(), $filters->utcEnd()]);
        $this->idFilter($base, 'sold_by', $filters->staff);
        $this->idFilter($base, 'customer_id', $filters->customer);
        $active = (clone $base)->where('status', 'completed');
        $count = (clone $active)->count();

        return $this->payload('Sales Report', $filters, [
            $this->money('Gross Sales', $this->sum($active, 'total_amount')),
            $this->count('Active Sales', $count), $this->money('Average Sale', $this->average($active, 'total_amount')),
            $this->count('Paid', (clone $active)->where('payment_status', 'paid')->count()),
            $this->count('Partial', (clone $active)->where('payment_status', 'partial')->count()),
            $this->count('Unpaid', (clone $active)->where('payment_status', 'unpaid')->count()),
            $this->count('Voided in Period', Sale::query()->where('status', 'voided')->whereBetween('voided_at', [$filters->utcStart(), $filters->utcEnd()])->count()),
        ], $base->latest('created_at')->paginate(15)->withQueryString(), 'sales');
    }

    public function collections(ReportFilters $filters): array
    {
        $base = SalePayment::query()->whereBetween('paid_at', [$filters->utcStart(), $filters->utcEnd()]);
        $this->idFilter($base, 'recorded_by', $filters->staff);
        $this->idFilter($base, 'customer_id', $filters->customer);
        if (PaymentMethod::tryFrom($filters->paymentMethod)) {
            $base->where('payment_method', $filters->paymentMethod);
        }
        $count = (clone $base)->count();
        $metrics = [$this->money('Customer Collections', $this->sum($base, 'amount')), $this->count('Payments', $count), $this->money('Average Payment', $this->average($base, 'amount'))];
        foreach (PaymentMethod::cases() as $method) {
            $metrics[] = $this->money($method->label(), $this->sum((clone $base)->where('payment_method', $method->value), 'amount'));
        }

        return $this->payload('Collections Report', $filters, $metrics, $base->with('sale:id,sale_number,customer_name_snapshot,sold_by_name_snapshot')->latest('paid_at')->paginate(15)->withQueryString(), 'collections');
    }

    public function receivables(ReportFilters $filters): array
    {
        $base = $this->currentReceivables();
        $this->idFilter($base, 'sold_by', $filters->staff);
        $this->idFilter($base, 'customer_id', $filters->customer);
        $mismatches = $this->paymentIntegrityMismatches(clone $base);

        $payload = $this->payload('Outstanding Receivables', $filters, [
            $this->money('Current Outstanding Receivables', $this->sum($base, 'balance_due')),
            $this->count('Outstanding Sales', (clone $base)->count()),
            $this->money('Partial Balances', $this->sum((clone $base)->where('payment_status', 'partial'), 'balance_due')),
            $this->money('Unpaid Balances', $this->sum((clone $base)->where('payment_status', 'unpaid'), 'balance_due')),
        ], $base->latest('created_at')->paginate(15)->withQueryString(), 'receivables');

        $payload['integrityWarning'] = $mismatches > 0 ? 'Sale aggregate and ledger inconsistency detected. No records were changed.' : null;

        return $payload;
    }

    public function expenses(ReportFilters $filters): array
    {
        $base = Expense::query()->whereBetween('incurred_at', [$filters->from, $filters->to]);
        $this->idFilter($base, 'recorded_by', $filters->staff);
        $this->idFilter($base, 'expense_category_id', $filters->category);
        if (PaymentMethod::tryFrom($filters->paymentMethod)) {
            $base->where('payment_method', $filters->paymentMethod);
        }
        $count = (clone $base)->count();

        $rows = (clone $base)->latest('incurred_at')->paginate(15)->withQueryString();

        $byPaymentMethod = (clone $base)->select('payment_method as label', DB::raw('SUM(amount) as value'))->groupBy('payment_method')->get()
            ->each(function ($row): void {
                $row->label = PaymentMethod::tryFrom($row->label)?->label() ?? $row->label;
            });

        return $this->payload('Expense Report', $filters, [$this->money('Operating Expenses', $this->sum($base, 'amount')), $this->count('Expenses', $count), $this->money('Average Expense', $this->average($base, 'amount'))], $rows, 'expenses', [
            'By Category' => (clone $base)->select('category_name_snapshot as label', DB::raw('SUM(amount) as value'))->groupBy('category_name_snapshot')->orderByDesc('value')->get(),
            'By Payment Method' => $byPaymentMethod,
        ]);
    }

    public function purchases(ReportFilters $filters): array
    {
        $base = Purchase::query()->whereBetween('received_at', [$filters->utcStart(), $filters->utcEnd()]);
        $this->idFilter($base, 'received_by', $filters->staff);
        $this->idFilter($base, 'supplier_id', $filters->supplier);
        $quantity = DB::table('purchase_items')->joinSub((clone $base)->select('id'), 'filtered_purchases', 'filtered_purchases.id', '=', 'purchase_items.purchase_id')->sum('quantity');

        $filteredIds = (clone $base)->select('id');
        $bySupplier = (clone $base)->select('supplier_name_snapshot as label', DB::raw('SUM(total_amount) value'))->groupBy('supplier_name_snapshot')->orderByDesc('value')->get()->each(fn ($row) => $row->format = 'money');
        $byReceiver = (clone $base)->select('received_by_name_snapshot as label', DB::raw('SUM(total_amount) value'))->groupBy('received_by_name_snapshot')->orderByDesc('value')->get()->each(fn ($row) => $row->format = 'money');
        $byProduct = DB::table('purchase_items')->joinSub($filteredIds, 'filtered_purchases', 'filtered_purchases.id', '=', 'purchase_items.purchase_id')->selectRaw('product_name_snapshot label, SUM(quantity) value')->groupBy('product_name_snapshot')->orderByDesc('value')->get()->each(fn ($row) => $row->format = 'quantity');

        return $this->payload('Purchase Report', $filters, [$this->money('Inventory Purchases', $this->sum($base, 'total_amount')), $this->count('Purchases', (clone $base)->count()), $this->quantity('Units Received', (string) $quantity)], $base->withCount('items')->latest('received_at')->paginate(15)->withQueryString(), 'purchases', ['By Supplier' => $bySupplier, 'By Product Quantity' => $byProduct, 'By Receiver' => $byReceiver]);
    }

    public function inventory(ReportFilters $filters): array
    {
        $base = InventoryMovement::query()->whereBetween('created_at', [$filters->utcStart(), $filters->utcEnd()]);
        $this->idFilter($base, 'product_id', $filters->product);
        $in = (string) (clone $base)->where('quantity_change', '>', 0)->sum('quantity_change');
        $out = (string) (clone $base)->where('quantity_change', '<', 0)->sum(DB::raw('ABS(quantity_change)'));

        return $this->payload('Inventory Movement Report', $filters, [$this->count('Movements', (clone $base)->count()), $this->quantity('Quantity In', $in), $this->quantity('Quantity Out', $out)], $base->with('product:id,name,sku')->latest('created_at')->paginate(15)->withQueryString(), 'inventory');
    }

    public function products(ReportFilters $filters): array
    {
        $base = SaleItem::query()->join('sales', 'sales.id', '=', 'sale_items.sale_id')->join('products', 'products.id', '=', 'sale_items.product_id')->where('sales.status', 'completed')->whereBetween('sales.created_at', [$filters->utcStart(), $filters->utcEnd()])
            ->when(ctype_digit($filters->product), fn ($q) => $q->where('sale_items.product_id', (int) $filters->product));
        $gross = (string) (clone $base)->sum('sale_items.line_total');
        $rows = $base->select(['sale_items.product_id', 'product_sku_snapshot', 'product_name_snapshot', 'products.current_stock', DB::raw('SUM(quantity) quantity_sold'), DB::raw('SUM(line_total) sales_value'), DB::raw('COUNT(DISTINCT sale_id) transaction_count')])
            ->groupBy('sale_items.product_id', 'product_sku_snapshot', 'product_name_snapshot', 'products.current_stock')->orderByDesc('sales_value')->paginate(15)->withQueryString();

        return $this->payload('Product Performance', $filters, [$this->money('Gross Product Sales', $gross)], $rows, 'products');
    }

    public function customers(ReportFilters $filters): array
    {
        $sales = DB::table('sales')->selectRaw('customer_id, COUNT(*) sale_count, SUM(total_amount) sales_value, SUM(balance_due) outstanding, MAX(created_at) latest_sale')->where('status', 'completed')->whereBetween('created_at', [$filters->utcStart(), $filters->utcEnd()])->groupBy('customer_id');
        $payments = DB::table('sale_payments')->join('sales', 'sales.id', '=', 'sale_payments.sale_id')->selectRaw('sale_payments.customer_id, SUM(sale_payments.amount) collected')->where('sales.status', 'completed')->whereBetween('sale_payments.paid_at', [$filters->utcStart(), $filters->utcEnd()])->groupBy('sale_payments.customer_id');
        $rows = Customer::query()->select(['customers.id', 'customer_code', 'first_name', 'last_name'])->joinSub($sales, 'sales_report', 'sales_report.customer_id', '=', 'customers.id')->leftJoinSub($payments, 'payment_report', 'payment_report.customer_id', '=', 'customers.id')->addSelect(['sale_count', 'sales_value', 'outstanding', 'latest_sale', DB::raw('COALESCE(collected, 0) collected')])->when(ctype_digit($filters->customer), fn ($q) => $q->where('customers.id', (int) $filters->customer))->orderByDesc('sales_value')->paginate(15)->withQueryString();

        return $this->payload('Customer Performance', $filters, [$this->count('Customers Who Purchased', $rows->total())], $rows, 'customers');
    }

    public function staff(ReportFilters $filters): array
    {
        $sales = DB::table('sales')->selectRaw('sold_by, COUNT(*) sale_count, SUM(total_amount) sales_value, SUM(balance_due) outstanding')->where('status', 'completed')->whereBetween('created_at', [$filters->utcStart(), $filters->utcEnd()])->groupBy('sold_by');
        $collections = DB::table('sale_payments')->join('sales', 'sales.id', '=', 'sale_payments.sale_id')->selectRaw('sales.sold_by, SUM(sale_payments.amount) seller_sale_collections')->where('sales.status', 'completed')->whereBetween('sale_payments.paid_at', [$filters->utcStart(), $filters->utcEnd()])->groupBy('sales.sold_by');
        $rows = User::query()->select(['users.id', 'users.name'])->joinSub($sales, 'sales_report', 'sales_report.sold_by', '=', 'users.id')->leftJoinSub($collections, 'collections_report', 'collections_report.sold_by', '=', 'users.id')->addSelect(['sale_count', 'sales_value', 'outstanding', DB::raw('COALESCE(seller_sale_collections, 0) seller_sale_collections')])->when(ctype_digit($filters->staff), fn ($q) => $q->where('users.id', (int) $filters->staff))->orderByDesc('sales_value')->paginate(15)->withQueryString();

        return $this->payload('Staff Sales Performance', $filters, [$this->count('Staff With Sales', $rows->total())], $rows, 'staff');
    }

    public function summary(ReportFilters $filters): array
    {
        $sales = Sale::query()->where('status', 'completed')->whereBetween('created_at', [$filters->utcStart(), $filters->utcEnd()]);
        $collections = SalePayment::query()->whereBetween('paid_at', [$filters->utcStart(), $filters->utcEnd()]);
        $expenses = Expense::query()->whereBetween('incurred_at', [$filters->from, $filters->to]);
        $purchases = Purchase::query()->whereBetween('received_at', [$filters->utcStart(), $filters->utcEnd()]);
        $receivables = $this->currentReceivables();

        $payload = $this->payload('Business Summary', $filters, [
            $this->money('Gross Sales', $this->sum($sales, 'total_amount')),
            $this->money('Customer Collections', $this->sum($collections, 'amount')),
            $this->money('Current Outstanding Receivables', $this->sum($receivables, 'balance_due')),
            $this->money('Operating Expenses', $this->sum($expenses, 'amount')),
            $this->money('Inventory Purchases', $this->sum($purchases, 'total_amount')),
            $this->count('Sales', (clone $sales)->count()), $this->count('Low-stock Products', Product::query()->active()->lowStock()->count()),
        ], null, 'summary');

        $payload['integrityWarning'] = $this->paymentIntegrityMismatches(clone $receivables) > 0 ? 'Sale aggregate and ledger inconsistency detected. No records were changed.' : null;

        return $payload;
    }

    public function filterOptions(string $type): array
    {
        $options = ['staffOptions' => collect(), 'customerOptions' => collect(), 'productOptions' => collect(), 'categoryOptions' => collect(), 'supplierOptions' => collect()];
        if (in_array($type, ['sales', 'collections', 'receivables', 'expenses', 'purchases', 'staff'], true)) {
            $options['staffOptions'] = User::orderBy('name')->limit(200)->get(['id', 'name']);
        }
        if (in_array($type, ['sales', 'collections', 'receivables', 'customers'], true)) {
            $options['customerOptions'] = Customer::orderBy('first_name')->limit(200)->get(['id', 'customer_code', 'first_name', 'last_name']);
        }
        if (in_array($type, ['inventory', 'products'], true)) {
            $options['productOptions'] = Product::withTrashed()->orderBy('name')->limit(200)->get(['id', 'sku', 'name']);
        }
        if ($type === 'expenses') {
            $options['categoryOptions'] = ExpenseCategory::orderBy('name')->limit(200)->get(['id', 'name']);
        }
        if ($type === 'purchases') {
            $options['supplierOptions'] = Supplier::orderBy('name')->limit(200)->get(['id', 'supplier_code', 'name']);
        }

        return $options;
    }

    private function payload(string $title, ReportFilters $filters, array $metrics, mixed $rows, string $type, array $breakdowns = []): array
    {
        return array_merge(compact('title', 'filters', 'metrics', 'rows', 'type', 'breakdowns'), ['integrityWarning' => null], $this->filterOptions($type));
    }

    private function sum(Builder $query, string $column): string
    {
        return (string) (clone $query)->sum($column);
    }

    private function average(Builder $query, string $column): string
    {
        $value = (string) ((clone $query)->avg($column) ?? '0');

        return Money::round($value);
    }

    private function currentReceivables(): Builder
    {
        return Sale::query()->where('status', 'completed')->where('balance_due', '>', 0);
    }

    private function paymentIntegrityMismatches(Builder $sales): int
    {
        $payments = DB::table('sale_payments')->selectRaw('sale_id, SUM(amount) ledger_paid')->groupBy('sale_id');
        $returns = DB::table('sale_returns')->selectRaw('sale_id, SUM(merchandise_value) ledger_returned')->groupBy('sale_id');
        $refunds = DB::table('sale_refunds')->selectRaw('sale_id, SUM(amount) ledger_refunded')->groupBy('sale_id');

        return $sales->leftJoinSub($payments, 'payment_ledger', 'payment_ledger.sale_id', '=', 'sales.id')
            ->leftJoinSub($returns, 'return_ledger', 'return_ledger.sale_id', '=', 'sales.id')
            ->leftJoinSub($refunds, 'refund_ledger', 'refund_ledger.sale_id', '=', 'sales.id')
            ->whereRaw('sales.amount_paid <> COALESCE(payment_ledger.ledger_paid, 0)
                OR sales.returned_amount <> COALESCE(return_ledger.ledger_returned, 0)
                OR sales.refunded_amount <> COALESCE(refund_ledger.ledger_refunded, 0)')->count('sales.id');
    }

    private function idFilter(Builder $query, string $column, string $value): void
    {
        if (ctype_digit($value)) {
            $query->where($column, (int) $value);
        }
    }

    private function money(string $label, string $value): array
    {
        return compact('label', 'value') + ['format' => 'money'];
    }

    private function quantity(string $label, string $value): array
    {
        return compact('label', 'value') + ['format' => 'quantity'];
    }

    private function count(string $label, int $value): array
    {
        return compact('label', 'value') + ['format' => 'count'];
    }
}
