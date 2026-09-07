<?php

namespace App\Actions\Alerts;

use App\Alerts\UnreadAlertCount;
use App\Enums\OperationalAlertType;
use App\Models\OperationalAlert;
use App\Models\OperationalAlertRecipient;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class MarkAllAlertsRead
{
    public function __construct(private readonly UnreadAlertCount $unread) {}

    /** @return int rows marked read */
    public function execute(User $actor): int
    {
        // Scoped in the WHERE clause itself to this operator's own unread rows *and* to the alert
        // types their current role may read, so there is no shape of this query that can touch
        // another account's state or silently mark a row they are no longer allowed to see.
        $marked = DB::transaction(fn (): int => OperationalAlertRecipient::query()
            ->forUser($actor)
            ->unread()
            ->whereIn('operational_alert_id', OperationalAlert::query()
                ->whereIn('type', OperationalAlertType::visibleToRole($actor->role))
                ->select('id'))
            ->update(['read_at' => now(), 'updated_at' => now()]));

        if ($marked > 0) {
            $this->unread->forget($actor);
        }

        return $marked;
    }
}
