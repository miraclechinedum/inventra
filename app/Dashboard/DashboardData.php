<?php

namespace App\Dashboard;

use App\Enums\UserRole;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\SaleRefund;
use App\Models\SaleReturn;
use App\Models\User;
use App\Reports\BusinessReports;
use App\Reports\ReportFilters;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Read-only operational dashboard queries.
 *
 * Period metrics honour the selected range in the business timezone. Current-state metrics
 * deliberately ignore the range because they describe the business right now. Every monetary
 * value is produced by a database aggregate and returned as an exact decimal string; no PHP
 * float participates in an authoritative figure.
 */
class DashboardData
{
    private const RECENT_LIMIT = 5;

    private const ALERT_LIMIT = 5;

    public function __construct(private readonly BusinessReports $reports) {}

    public function for(User $user, ReportFilters $filters): array
    {
        return $user->role === UserRole::SalesRep
            ? $this->forSalesRep($user, $filters)
            : $this->forManagement($filters);
    }

    /* ---------------------------------------------------------------- management */

    private function forManagement(ReportFilters $filters): array
    {
        [$start, $end] = [$filters->utcStart(), $filters->utcEnd()];

        $sales = DB::table('sales')->where('status', 'completed')->whereBetween('created_at', [$start, $end])
            ->selectRaw('COALESCE(SUM(total_amount), 0) total, COUNT(*) sale_count')->first();
        $receivables = DB::table('sales')->where('status', 'completed')->where('balance_due', '>', 0)
            ->selectRaw('COALESCE(SUM(balance_due), 0) total, COUNT(*) sale_count')->first();
        $credit = DB::table('sales')->where('status', 'completed')->where('refundable_credit', '>', 0)
            ->selectRaw('COALESCE(SUM(refundable_credit), 0) total, COUNT(*) sale_count')->first();
        $stock = DB::table('products')->whereNull('deleted_at')->where('is_active', true)
            ->selectRaw('SUM(current_stock <= reorder_level) low, SUM(current_stock <= 0) zero')->first();
        $staff = DB::table('users')
            ->selectRaw("SUM(status = 'active') active, SUM(status = 'inactive') inactive, SUM(status = 'locked') locked")->first();

        return [
            'scope' => 'management',
            'periodMetrics' => [
                $this->money('Gross Sales', $sales->total, 'Completed, non-voided Sale totals transacted in this period. Returns never reduce it.'),
                $this->money('Customer Collections', $this->sumIn(SalePayment::query(), 'paid_at', $start, $end, 'amount'), 'Sale Payment receipts recorded in this period. Refunds never reduce it.'),
                $this->money('Returns Value', $this->sumIn(SaleReturn::query(), 'returned_at', $start, $end, 'merchandise_value'), 'Merchandise value accepted back in this period.'),
                $this->money('Refunds Paid', $this->sumIn(SaleRefund::query(), 'refunded_at', $start, $end, 'amount'), 'Cash returned to customers in this period. Not an Expense.'),
                $this->money('Operating Expenses', $this->sumIn(Expense::query(), 'incurred_at', $filters->from, $filters->to, 'amount'), 'Non-inventory operating costs incurred in this period.'),
                $this->money('Inventory Purchases', $this->sumIn(Purchase::query(), 'received_at', $start, $end, 'total_amount'), 'Stock received from Suppliers in this period.'),
                $this->count('Sales Recorded', (int) $sales->sale_count, 'Completed, non-voided Sales transacted in this period.'),
            ],
            'currentMetrics' => [
                $this->money('Current Outstanding Receivables', $receivables->total, 'Return-adjusted balances owed right now, across all periods.'),
                $this->money('Refundable Customer Credit', $credit->total, 'Credit currently owed back to customers, across all periods.'),
                $this->count('Low-stock Products', (int) $stock?->low, 'Active Products at or below their reorder level right now.'),
                $this->count('Active Customers', DB::table('customers')->where('is_active', true)->count(), 'Customers currently active.'),
                $this->count('Active Staff', (int) $staff?->active, 'Staff accounts currently active.'),
            ],
            'alerts' => $this->managementAlerts($stock, $staff, $receivables, $credit),
            'recent' => $this->managementRecent(),
        ];
    }

    private function managementAlerts(?object $stock, ?object $staff, object $receivables, object $credit): array
    {
        $integrity = $this->reports->ledgerIntegrityMismatches();
        $alerts = [];

        if ($integrity > 0) {
            $alerts[] = $this->alert('critical', 'Sale aggregate and ledger inconsistency detected',
                $integrity.' '.($integrity === 1 ? 'Sale does' : 'Sales do').' not agree with the payment, return, or refund ledger. No records were changed; this dashboard only detects.');
        }
        if ((int) $stock?->zero > 0) {
            $alerts[] = $this->alert('critical', 'Active Products out of stock',
                $stock->zero.' active '.((int) $stock->zero === 1 ? 'Product is' : 'Products are').' at zero stock.');
        }
        if ((int) $stock?->low > 0) {
            $alerts[] = $this->alert('attention', 'Active Products at or below reorder level', $stock->low.' to review.');
        }
        if ((int) $receivables->sale_count > 0) {
            $alerts[] = $this->alert('attention', 'Sales with outstanding balances',
                $receivables->sale_count.' outstanding, totalling ₦'.Money::format((string) $receivables->total).'.');
        }
        if ((int) $credit->sale_count > 0) {
            $alerts[] = $this->alert('information', 'Sales holding refundable customer credit',
                $credit->sale_count.' holding ₦'.Money::format((string) $credit->total).'.');
        }
        if ((int) $staff?->locked > 0) {
            $alerts[] = $this->alert('attention', 'Locked staff accounts', $staff->locked.' locked.');
        }
        if ((int) $staff?->inactive > 0) {
            $alerts[] = $this->alert('information', 'Inactive staff accounts', $staff->inactive.' inactive.');
        }

        return [
            'items' => $alerts,
            'outstandingSales' => Sale::query()->where('status', 'completed')->where('balance_due', '>', 0)
                ->orderBy('created_at')->orderBy('id')->limit(self::ALERT_LIMIT)
                ->get(['id', 'sale_number', 'customer_name_snapshot', 'balance_due', 'created_at']),
            'creditSales' => Sale::query()->where('status', 'completed')->where('refundable_credit', '>', 0)
                ->orderByDesc('refundable_credit')->orderBy('id')->limit(self::ALERT_LIMIT)
                ->get(['id', 'sale_number', 'customer_name_snapshot', 'refundable_credit', 'updated_at']),
            'lowStockProducts' => Product::query()->active()->lowStock()->orderBy('current_stock')->orderBy('id')
                ->limit(self::ALERT_LIMIT)->get(['id', 'sku', 'name', 'unit', 'current_stock', 'reorder_level']),
        ];
    }

    private function managementRecent(): array
    {
        return [
            'sales' => Sale::query()->where('status', 'completed')->latest('created_at')->latest('id')->limit(self::RECENT_LIMIT)
                ->get(['id', 'sale_number', 'customer_name_snapshot', 'total_amount', 'balance_due', 'payment_status', 'created_at']),
            'collections' => SalePayment::query()->with('sale:id,sale_number')->latest('paid_at')->latest('id')->limit(self::RECENT_LIMIT)
                ->get(['id', 'sale_id', 'payment_number', 'amount', 'payment_method', 'paid_at']),
            'returns' => SaleReturn::query()->latest('returned_at')->latest('id')->limit(self::RECENT_LIMIT)
                ->get(['id', 'return_number', 'sale_number_snapshot', 'customer_name_snapshot', 'merchandise_value', 'returned_at']),
            'refunds' => SaleRefund::query()->latest('refunded_at')->latest('id')->limit(self::RECENT_LIMIT)
                ->get(['id', 'refund_number', 'sale_number_snapshot', 'customer_name_snapshot', 'amount', 'payment_method', 'refunded_at']),
            'expenses' => Expense::query()->latest('incurred_at')->latest('id')->limit(self::RECENT_LIMIT)
                ->get(['id', 'expense_number', 'category_name_snapshot', 'description', 'amount', 'incurred_at']),
            'purchases' => Purchase::query()->latest('received_at')->latest('id')->limit(self::RECENT_LIMIT)
                ->get(['id', 'purchase_number', 'supplier_name_snapshot', 'total_amount', 'received_at']),
        ];
    }

    /* ------------------------------------------------------------- sales rep */

    private function forSalesRep(User $user, ReportFilters $filters): array
    {
        [$start, $end] = [$filters->utcStart(), $filters->utcEnd()];

        $sales = DB::table('sales')->where('status', 'completed')->where('sold_by', $user->id)
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('COALESCE(SUM(total_amount), 0) total, COUNT(*) sale_count')->first();
        // Seller-attributed collections: receipts against Sales this representative sold,
        // matching the Staff Performance report rather than inventing a new attribution.
        $collections = (string) DB::table('sale_payments')
            ->join('sales', 'sales.id', '=', 'sale_payments.sale_id')
            ->where('sales.sold_by', $user->id)->whereBetween('sale_payments.paid_at', [$start, $end])
            ->selectRaw('COALESCE(SUM(sale_payments.amount), 0) total')->value('total');
        $receivables = DB::table('sales')->where('status', 'completed')->where('sold_by', $user->id)
            ->where('balance_due', '>', 0)
            ->selectRaw('COALESCE(SUM(balance_due), 0) total, COUNT(*) sale_count')->first();
        $lowStock = Product::query()->active()->lowStock()->count();

        $alerts = [];
        if ($lowStock > 0) {
            $alerts[] = $this->alert('attention', 'Products at or below reorder level', $lowStock.' to review before selling.');
        }
        if ((int) $receivables->sale_count > 0) {
            $alerts[] = $this->alert('attention', 'Your Sales with outstanding balances',
                $receivables->sale_count.' outstanding, totalling ₦'.Money::format((string) $receivables->total).'.');
        }

        return [
            'scope' => 'sales_rep',
            'periodMetrics' => [
                $this->money('My Gross Sales', $sales->total, 'Your completed, non-voided Sale totals transacted in this period.'),
                $this->money('My Collections', $collections, 'Sale Payment receipts against Sales you sold, recorded in this period.'),
                $this->count('My Sales Recorded', (int) $sales->sale_count, 'Your completed, non-voided Sales transacted in this period.'),
            ],
            'currentMetrics' => [
                $this->money('My Outstanding Receivables', $receivables->total, 'Return-adjusted balances owed on Sales you sold, across all periods.'),
                $this->count('Low-stock Products', $lowStock, 'Active Products at or below their reorder level right now.'),
                $this->count('Active Customers', DB::table('customers')->where('is_active', true)->count(), 'Customers currently active.'),
            ],
            'alerts' => [
                'items' => $alerts,
                'outstandingSales' => Sale::query()->where('status', 'completed')->where('sold_by', $user->id)
                    ->where('balance_due', '>', 0)->orderBy('created_at')->orderBy('id')->limit(self::ALERT_LIMIT)
                    ->get(['id', 'sale_number', 'customer_name_snapshot', 'balance_due', 'created_at']),
                'creditSales' => collect(),
                'lowStockProducts' => Product::query()->active()->lowStock()->orderBy('current_stock')->orderBy('id')
                    ->limit(self::ALERT_LIMIT)->get(['id', 'sku', 'name', 'unit', 'current_stock', 'reorder_level']),
            ],
            'recent' => [
                'sales' => Sale::query()->where('status', 'completed')->where('sold_by', $user->id)
                    ->latest('created_at')->latest('id')->limit(self::RECENT_LIMIT)
                    ->get(['id', 'sale_number', 'customer_name_snapshot', 'total_amount', 'balance_due', 'payment_status', 'created_at']),
                'collections' => collect(), 'returns' => collect(), 'refunds' => collect(),
                'expenses' => collect(), 'purchases' => collect(),
            ],
        ];
    }

    /* ------------------------------------------------------------------ helpers */

    private function sumIn($query, string $column, mixed $start, mixed $end, string $sum): string
    {
        return (string) $query->whereBetween($column, [$start, $end])
            ->selectRaw("COALESCE(SUM({$sum}), 0) total")->value('total');
    }

    private function money(string $label, mixed $value, string $meaning): array
    {
        return ['label' => $label, 'value' => (string) $value, 'format' => 'money', 'meaning' => $meaning];
    }

    private function count(string $label, int $value, string $meaning): array
    {
        return ['label' => $label, 'value' => $value, 'format' => 'count', 'meaning' => $meaning];
    }

    private function alert(string $severity, string $title, string $detail): array
    {
        return ['severity' => $severity, 'title' => $title, 'detail' => $detail];
    }
}
