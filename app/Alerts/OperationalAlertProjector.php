<?php

namespace App\Alerts;

use App\Enums\OperationalAlertStatus;
use App\Enums\OperationalAlertType;
use App\Models\OperationalAlert;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of operational_alerts and operational_alert_recipients.
 *
 * Alerts are a derived projection of domain truth, so this class never reads or writes a business
 * record: it is handed a condition that some evaluator already established, and its whole job is to
 * make the alert tables agree with it — exactly once, under concurrency.
 */
class OperationalAlertProjector
{
    /**
     * Concurrent inserts on the same active_key contend, and MySQL resolves that contention either
     * as a duplicate-key error or as a deadlock, unpredictably. The duplicate is handled below by
     * adopting the winner; a deadlock is not something this class can reason about, so the whole
     * transaction is retried. Laravel retries only on concurrency errors, so a genuine failure
     * still surfaces on the first attempt.
     *
     * This depends on the projector being the outermost transaction: Laravel refuses to retry a
     * nested one, because MySQL has already rolled the whole outer transaction back. Both callers
     * satisfy that — the observers run through DB::afterCommit, so the business transaction is
     * already committed and gone, and reconciliation runs with no transaction of its own. Wrapping
     * either in an outer transaction would silently disable these retries.
     */
    private const CONCURRENCY_ATTEMPTS = 3;

    /**
     * Recipient ids per role set, resolved once per projector instance — which means once per
     * reconciliation run and once per request. Deliberately not static and not cached across runs:
     * a Manager deactivated between two runs must stop receiving new deliveries immediately.
     *
     * @var array<string, list<int>>
     */
    private array $recipientCache = [];

    /**
     * Opens the alert for a condition that is currently true, or refreshes the active one in place
     * when its severity or wording has moved (low stock becoming zero stock keeps one lifecycle).
     *
     * Concurrency rests on the UNIQUE `active_key`, not on the existence check: two evaluators may
     * both find nothing and both insert, and the loser is told so by the database rather than
     * silently creating a duplicate.
     */
    public function open(AlertCondition $condition): OperationalAlert
    {
        return DB::transaction(function () use ($condition): OperationalAlert {
            $existing = OperationalAlert::query()
                ->where('active_key', $condition->activeKey())
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $this->refresh($existing, $condition);
            }

            try {
                return $this->create($condition);
            } catch (UniqueConstraintViolationException) {
                // A concurrent evaluator opened the same alert between our read and our insert.
                // Its row is the winner; adopt it rather than raising a duplicate.
                $winner = OperationalAlert::query()->where('active_key', $condition->activeKey())->first();

                return $winner === null ? throw new \RuntimeException('Alert vanished during concurrent open.') : $this->refresh($winner, $condition);
            }
        }, self::CONCURRENCY_ATTEMPTS);
    }

    /**
     * Closes the active alert for a condition that is no longer true. Recipient rows and the alert
     * itself are preserved: resolution is evidence, not cleanup. Returns true when it resolved one.
     */
    public function resolve(OperationalAlertType $type, string $subjectType, int $subjectId): bool
    {
        return DB::transaction(function () use ($type, $subjectType, $subjectId): bool {
            $alert = OperationalAlert::query()
                ->where('active_key', OperationalAlert::activeKeyFor($type, $subjectType, $subjectId))
                ->lockForUpdate()
                ->first();

            if ($alert === null) {
                return false;
            }

            $alert->forceFill([
                'status' => OperationalAlertStatus::Resolved,
                // Releasing the key is what lets the same condition recur later as a new occurrence.
                'active_key' => null,
                'resolved_at' => now(),
            ])->save();

            return true;
        }, self::CONCURRENCY_ATTEMPTS);
    }

    /** Resolves every active alert of a type whose subject id is not in the still-true set. */
    public function resolveMissing(OperationalAlertType $type, string $subjectType, array $stillTrueIds): int
    {
        $stale = OperationalAlert::query()->active()
            ->where('type', $type->value)
            ->where('subject_type', $subjectType)
            ->when($stillTrueIds !== [], fn (Builder $query) => $query->whereNotIn('subject_id', $stillTrueIds))
            ->pluck('subject_id');

        $resolved = 0;

        foreach ($stale as $subjectId) {
            $resolved += $this->resolve($type, $subjectType, (int) $subjectId) ? 1 : 0;
        }

        return $resolved;
    }

    /**
     * The active accounts holding a role this alert type is delivered to.
     *
     * Recipients depend only on the type's role set, never on the subject, so a sweep over a
     * thousand low-stock Products needs one query per distinct role set rather than one per Product.
     *
     * @return list<int>
     */
    private function recipientIdsFor(OperationalAlertType $type): array
    {
        $roles = array_map(static fn ($role): string => $role->value, $type->recipientRoles());
        sort($roles);
        $key = implode(',', $roles);

        return $this->recipientCache[$key] ??= User::query()
            ->whereIn('role', $roles)
            // Inactive and locked accounts cannot sign in at all, so delivering to them would only
            // create rows nobody can ever read. Existing rows are never removed.
            ->where('status', 'active')
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    private function create(AlertCondition $condition): OperationalAlert
    {
        // Occurrence numbering makes recurrence legible: "this is the third time this Product has
        // fallen to or below its reorder level", without any row being rewritten.
        $occurrence = OperationalAlert::query()
            ->where('type', $condition->type->value)
            ->where('subject_type', $condition->subjectType)
            ->where('subject_id', $condition->subjectId)
            ->count() + 1;

        $alert = new OperationalAlert;
        $alert->forceFill([
            'type' => $condition->type,
            'severity' => $condition->severity,
            'status' => OperationalAlertStatus::Active,
            'subject_type' => $condition->subjectType,
            'subject_id' => $condition->subjectId,
            'subject_label_snapshot' => mb_substr($condition->subjectLabel, 0, 191),
            'title' => mb_substr($condition->title, 0, 191),
            'message' => mb_substr($condition->message, 0, 500),
            'active_key' => $condition->activeKey(),
            'occurrence' => $occurrence,
            'first_detected_at' => now(),
            'resolved_at' => null,
        ])->save();

        // Same transaction as the alert: a half-delivered alert must not be representable.
        $this->attachRecipients($alert, $condition->type);

        return $alert;
    }

    private function refresh(OperationalAlert $alert, AlertCondition $condition): OperationalAlert
    {
        $changes = array_filter([
            'severity' => $alert->severity === $condition->severity ? null : $condition->severity,
            'subject_label_snapshot' => $alert->subject_label_snapshot === $condition->subjectLabel ? null : mb_substr($condition->subjectLabel, 0, 191),
            'title' => $alert->title === $condition->title ? null : mb_substr($condition->title, 0, 191),
            'message' => $alert->message === $condition->message ? null : mb_substr($condition->message, 0, 500),
        ], static fn (mixed $value): bool => $value !== null);

        if ($changes !== []) {
            $alert->forceFill($changes)->save();
        }

        // A recipient set can widen after the alert opened: a Manager hired today should see the
        // low-stock alert that opened yesterday, and the UNIQUE key makes this safe to repeat.
        $this->attachRecipients($alert, $condition->type);

        return $alert;
    }

    /** Delivers to every active account holding a role this alert type is meant for. */
    private function attachRecipients(OperationalAlert $alert, OperationalAlertType $type): void
    {
        $userIds = $this->recipientIdsFor($type);

        $existing = $alert->recipients()->pluck('user_id')->all();
        $now = now();

        $rows = [];

        foreach ($userIds as $userId) {
            if (in_array($userId, $existing, true)) {
                continue;
            }

            $rows[] = [
                'operational_alert_id' => $alert->getKey(),
                'user_id' => $userId,
                'read_at' => null,
                'acknowledged_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            // Ignores rows a concurrent evaluator inserted first; the UNIQUE index decides.
            DB::table('operational_alert_recipients')->insertOrIgnore($rows);
        }
    }
}
