<?php

namespace App\Reports;

use App\Enums\SaleStatus;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What a member of staff actually did, derived entirely from existing business records.
 *
 * There is no activity table and no counter column: every figure below is read from the tables that
 * already own the facts, so the numbers cannot drift away from the records they describe and nothing
 * has to be backfilled or kept in step. The cost is a handful of aggregate queries per page view,
 * which is the right trade for a page an Admin opens occasionally.
 *
 * This complements the existing security-event feed, which answers "what happened to this account";
 * this answers "what work did this person do".
 */
class EmployeeActivity
{
    /**
     * @return array{from: Carbon, to: Carbon, metrics: array<int, array<string, string>>, recent: Collection<int, object>, totals: array<string, string>}
     */
    public function forUser(User $user, ?string $from = null, ?string $to = null): array
    {
        // Default window: the last 30 days, inclusive of today.
        $start = $this->parseDate($from) ?? now()->subDays(29)->startOfDay();
        $end = $this->parseDate($to) ?? now()->endOfDay();

        if ($start->greaterThan($end)) {
            [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
        }

        $start = $start->startOfDay();
        $end = $end->endOfDay();

        $sales = $this->sum('sales', 'sold_by', $user->id, 'created_at', $start, $end, 'total_amount',
            fn ($query) => $query->where('status', SaleStatus::Completed->value));
        $voided = $this->count('sales', 'sold_by', $user->id, 'created_at', $start, $end,
            fn ($query) => $query->where('status', SaleStatus::Voided->value));
        $payments = $this->sum('sale_payments', 'recorded_by', $user->id, 'paid_at', $start, $end, 'amount');
        $returns = $this->sum('sale_returns', 'returned_by', $user->id, 'returned_at', $start, $end, 'merchandise_value');
        $refunds = $this->sum('sale_refunds', 'refunded_by', $user->id, 'refunded_at', $start, $end, 'amount');
        $expenses = $this->sum('expenses', 'recorded_by', $user->id, 'created_at', $start, $end, 'amount');
        $purchases = $this->sum('purchases', 'received_by', $user->id, 'received_at', $start, $end, 'total_amount');
        $adjustments = $this->count('inventory_movements', 'performed_by', $user->id, 'created_at', $start, $end);
        $discountsAsked = $this->count('sale_discount_requests', 'requested_by', $user->id, 'requested_at', $start, $end);
        $discountsDecided = $this->count('sale_discount_requests', 'decided_by', $user->id, 'decided_at', $start, $end);
        $productsCreated = $this->count('products', 'created_by', $user->id, 'created_at', $start, $end);

        return [
            'from' => $start,
            'to' => $end,
            'metrics' => [
                ['label' => 'Sales recorded', 'count' => (string) $sales['count'], 'value' => $sales['sum'], 'note' => 'Completed sales'],
                ['label' => 'Sales voided', 'count' => (string) $voided, 'value' => null, 'note' => 'Later voided by an administrator'],
                ['label' => 'Payments taken', 'count' => (string) $payments['count'], 'value' => $payments['sum'], 'note' => 'Cash collected against sales'],
                ['label' => 'Returns recorded', 'count' => (string) $returns['count'], 'value' => $returns['sum'], 'note' => 'Merchandise value returned'],
                ['label' => 'Refunds paid out', 'count' => (string) $refunds['count'], 'value' => $refunds['sum'], 'note' => 'Cash refunded to customers'],
                ['label' => 'Expenses recorded', 'count' => (string) $expenses['count'], 'value' => $expenses['sum'], 'note' => 'Business spending entered'],
                ['label' => 'Purchases received', 'count' => (string) $purchases['count'], 'value' => $purchases['sum'], 'note' => 'Stock received from suppliers'],
                ['label' => 'Stock movements', 'count' => (string) $adjustments, 'value' => null, 'note' => 'Every inventory change they caused'],
                ['label' => 'Discounts requested', 'count' => (string) $discountsAsked, 'value' => null, 'note' => 'Price adjustments they asked for'],
                ['label' => 'Discounts decided', 'count' => (string) $discountsDecided, 'value' => null, 'note' => 'Approvals or declines they made'],
                ['label' => 'Products created', 'count' => (string) $productsCreated, 'value' => null, 'note' => 'New catalogue entries'],
            ],
            'totals' => [
                'sales_value' => $sales['sum'],
                'collected' => $payments['sum'],
                'refunded' => $refunds['sum'],
            ],
            'recent' => $this->recentActions($user, $start, $end),
        ];
    }

    /**
     * The last few audited actions in the window, so the numbers above can be traced to specific
     * events rather than standing on their own.
     *
     * @return Collection<int, object>
     */
    private function recentActions(User $user, Carbon $start, Carbon $end)
    {
        return DB::table('audit_logs')
            ->select('action', 'subject_label_snapshot', 'created_at')
            ->where('actor_id', $user->id)
            ->whereBetween('created_at', [$start, $end])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(15)
            ->get();
    }

    /**
     * @return array{count: int, sum: string}
     */
    private function sum(
        string $table,
        string $actorColumn,
        int $userId,
        string $dateColumn,
        Carbon $start,
        Carbon $end,
        string $valueColumn,
        ?callable $filter = null,
    ): array {
        $query = DB::table($table)
            ->where($actorColumn, $userId)
            ->whereBetween($dateColumn, [$start, $end]);

        if ($filter !== null) {
            $filter($query);
        }

        $row = $query->selectRaw('COUNT(*) AS row_count, COALESCE(SUM('.$valueColumn.'), 0) AS row_sum')->first();

        return [
            'count' => (int) ($row->row_count ?? 0),
            // Money stays a string end to end; SUM() comes back as a string from a DECIMAL column.
            'sum' => Money::round((string) ($row->row_sum ?? '0')),
        ];
    }

    private function count(
        string $table,
        string $actorColumn,
        int $userId,
        string $dateColumn,
        Carbon $start,
        Carbon $end,
        ?callable $filter = null,
    ): int {
        $query = DB::table($table)
            ->where($actorColumn, $userId)
            ->whereBetween($dateColumn, [$start, $end]);

        if ($filter !== null) {
            $filter($query);
        }

        return $query->count();
    }

    private function parseDate(?string $value): ?Carbon
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
