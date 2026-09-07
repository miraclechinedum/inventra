<?php

namespace App\Alerts;

use App\Enums\OperationalAlertType;
use App\Models\OperationalAlertRecipient;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reads one operator's notification list. Every query is scoped to their own recipient rows *and* to
 * the alert types their current role may read, in SQL — so a demoted operator cannot see a title, a
 * message, a subject snapshot, a severity, or even infer a count from the pagination totals.
 */
class AlertInbox
{
    public const PER_PAGE = 25;

    public function paginate(User $user, AlertFilters $filters): LengthAwarePaginator
    {
        return $this->query($user, $filters)
            // Newest alert first. Ordering on the alert id rather than its created_at is both a
            // total order — one recipient row per alert per user, so no ties are possible — and
            // index-backed by (user_id, operational_alert_id), which is what keeps pagination
            // deterministic under identical timestamps without a filesort.
            ->orderByDesc('operational_alert_recipients.operational_alert_id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    private function query(User $user, AlertFilters $filters): Builder
    {
        return OperationalAlertRecipient::query()
            ->forUser($user)
            // Current entitlement, not entitlement at delivery time. Empty for a Sales
            // Representative, which makes the whole inbox empty rather than filtered after loading.
            ->whereIn('operational_alerts.type', OperationalAlertType::visibleToRole($user->role))
            // Joined for filtering and ordering only; the alert itself is eager-loaded so a page of
            // notifications costs one query for the rows and one for their alerts, never one each.
            ->join('operational_alerts', 'operational_alerts.id', '=', 'operational_alert_recipients.operational_alert_id')
            ->select('operational_alert_recipients.*')
            ->with('alert')
            ->when($filters->status !== '', fn (Builder $q) => $q->where('operational_alerts.status', $filters->status))
            ->when($filters->severity !== '', fn (Builder $q) => $q->where('operational_alerts.severity', $filters->severity))
            ->when($filters->type !== '', fn (Builder $q) => $q->where('operational_alerts.type', $filters->type))
            ->when($filters->readState === 'unread', fn (Builder $q) => $q->whereNull('operational_alert_recipients.read_at'))
            ->when($filters->readState === 'read', fn (Builder $q) => $q->whereNotNull('operational_alert_recipients.read_at'))
            ->when($filters->readState === 'acknowledged', fn (Builder $q) => $q->whereNotNull('operational_alert_recipients.acknowledged_at'));
    }
}
