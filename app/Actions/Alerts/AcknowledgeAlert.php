<?php

namespace App\Actions\Alerts;

use App\Alerts\UnreadAlertCount;
use App\Models\OperationalAlertRecipient;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Records that an operator explicitly took ownership of an alert.
 *
 * Acknowledging is a deliberate human statement — "I am aware of this" — so unlike read state it
 * belongs in the Audit Trail. It says nothing about the underlying condition: an acknowledged
 * low-stock alert stays active until stock actually recovers.
 */
class AcknowledgeAlert
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly UnreadAlertCount $unread,
    ) {}

    /** @return bool whether this call was the one that acknowledged it */
    public function execute(User $actor, OperationalAlertRecipient $recipient): bool
    {
        $changed = DB::transaction(function () use ($actor, $recipient): bool {
            $locked = OperationalAlertRecipient::query()->whereKey($recipient->getKey())->lockForUpdate()->firstOrFail();

            // Idempotent: acknowledging twice is not two acknowledgements, and must not write a
            // second audit event.
            if ($locked->acknowledged_at !== null) {
                return false;
            }

            $now = now();

            $locked->forceFill([
                // Acknowledging implies having seen it, and the table's CHECK constraint enforces
                // that pairing. An earlier read keeps its original timestamp.
                'read_at' => $locked->read_at ?? $now,
                'acknowledged_at' => $now,
            ])->save();

            $alert = $locked->alert()->firstOrFail();

            $this->audit->record('operational_alert_acknowledged', $alert, $actor, metadata: [
                'alert_type' => $alert->type->value,
                'alert_severity' => $alert->severity->value,
            ]);

            return true;
        });

        if ($changed) {
            $this->unread->forget($actor);
        }

        return $changed;
    }
}
