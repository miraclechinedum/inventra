<?php

namespace App\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Read-only query layer for the audit trail. It never writes, and it reads the immutable snapshot
 * columns rather than joining to live Users and subjects, so history stays legible after the
 * records it describes are renamed, deactivated or removed.
 */
class AuditTrail
{
    private const PER_PAGE = 25;

    public function paginate(AuditFilters $filters): LengthAwarePaginator
    {
        return $this->query($filters)
            // Rows written before actor snapshots existed carry NULL snapshots and fall back to the
            // live actor for display. Eager-loading keeps that fallback a constant two queries for
            // the page instead of one lazy load per legacy row.
            ->with('actor:id,name')
            ->select([
                'id', 'action', 'actor_id', 'actor_name_snapshot', 'actor_role_snapshot',
                'auditable_type', 'auditable_id', 'subject_label_snapshot', 'created_at',
            ])
            ->latest('created_at')
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    /** Distinct event names actually present, so the filter never offers an empty result set. */
    public function eventOptions(): Collection
    {
        return AuditLog::query()->distinct()->orderBy('action')->pluck('action');
    }

    /** Actors that have actually recorded events, labelled from the live User where it still exists. */
    public function actorOptions(): Collection
    {
        return User::query()
            ->whereIn('id', AuditLog::query()->whereNotNull('actor_id')->distinct()->select('actor_id'))
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name']);
    }

    private function query(AuditFilters $filters): Builder
    {
        $query = AuditLog::query()
            ->whereBetween('created_at', [$filters->utcStart(), $filters->utcEnd()]);

        if (ctype_digit($filters->actor)) {
            $query->where('actor_id', (int) $filters->actor);
        }

        if ($filters->event !== '') {
            $query->where('action', $filters->event);
        }

        if (($subject = $filters->subjectClass()) !== null) {
            $query->where('auditable_type', $subject);
        }

        if ($filters->search !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters->search);
            $query->where(function (Builder $scope) use ($escaped): void {
                $scope->where('subject_label_snapshot', 'like', '%'.$escaped.'%')
                    ->orWhere('actor_name_snapshot', 'like', '%'.$escaped.'%');
            });
        }

        return $query;
    }
}
