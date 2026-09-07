<?php

namespace App\Alerts;

use App\Enums\OperationalAlertType;
use App\Models\OperationalAlertRecipient;
use App\Models\User;

/**
 * The one place the navigation badge reads its number from. Memoised per user for the life of one
 * request so a layout that renders the badge more than once still costs a single query.
 *
 * Container-scoped, which on the PHP-FPM deployment target means request-scoped. The read and
 * acknowledge actions drop the memo after they commit, the same contract App\Settings\BusinessSettings
 * follows; a long-lived worker would need the same explicit refresh at the request boundary.
 */
class UnreadAlertCount
{
    /** Display cap: a very noisy installation renders "99+" rather than a five-digit badge. */
    public const CAP = 99;

    /** @var array<int, int> */
    private array $memo = [];

    public function for(User $user): int
    {
        // Counts only what this role may currently read, so a demotion drops the badge immediately
        // and an operator cannot infer how many alerts they have lost access to.
        //
        // A plain COUNT over the (user_id, read_at) index is a covering scan of one operator's
        // unread rows. An earlier LIMIT here was ineffective — MySQL applies LIMIT to the one-row
        // aggregate, not to the rows scanned — so it is gone rather than left implying a bound that
        // never existed. The cap below is a display cap only.
        return $this->memo[$user->getKey()] ??= OperationalAlertRecipient::query()
            ->forUser($user)
            ->unread()
            ->join('operational_alerts', 'operational_alerts.id', '=', 'operational_alert_recipients.operational_alert_id')
            ->whereIn('operational_alerts.type', OperationalAlertType::visibleToRole($user->role))
            ->count();
    }

    /** Display form for the badge: an empty string when there is nothing to show. */
    public function badge(User $user): string
    {
        $count = $this->for($user);

        return match (true) {
            $count === 0 => '',
            $count > self::CAP => self::CAP.'+',
            default => (string) $count,
        };
    }

    public function forget(?User $user = null): void
    {
        if ($user === null) {
            $this->memo = [];

            return;
        }

        unset($this->memo[$user->getKey()]);
    }
}
