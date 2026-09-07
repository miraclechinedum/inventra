<?php

namespace App\Actions\Alerts;

use App\Alerts\UnreadAlertCount;
use App\Models\OperationalAlertRecipient;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Marks one recipient row read. Read state is private housekeeping, not business evidence, so it is
 * deliberately absent from the Audit Trail: auditing every glance would bury the events that matter.
 */
class MarkAlertRead
{
    public function __construct(private readonly UnreadAlertCount $unread) {}

    /** @return bool whether this call was the one that marked it read */
    public function execute(User $actor, OperationalAlertRecipient $recipient): bool
    {
        $changed = DB::transaction(function () use ($recipient): bool {
            $locked = OperationalAlertRecipient::query()->whereKey($recipient->getKey())->lockForUpdate()->firstOrFail();

            // Idempotent: re-reading an already-read alert must not move the timestamp, because
            // read_at answers "when did they first see this".
            if ($locked->read_at !== null) {
                return false;
            }

            $locked->forceFill(['read_at' => now()])->save();

            return true;
        });

        if ($changed) {
            $this->unread->forget($actor);
        }

        return $changed;
    }
}
