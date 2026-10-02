<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * A single-use request token belongs to the Business of the operator it was issued to.
 *
 * The Business is read from that operator's persisted account, never from the request. A token
 * stamped with a different Business, or issued against another Business's sale, is refused.
 * There is no global scope: expired tokens are pruned by a scheduled command that has no Business,
 * and every lookup names the Business explicitly instead.
 */
trait OwnedByIssuingOperator
{
    use BelongsToBusiness;

    public static function bootOwnedByIssuingOperator(): void
    {
        static::creating(function (self $request): void {
            $business = DB::table('users')->where('id', $request->actor_id)->value('business_id');

            if ($business === null) {
                throw new LogicException('A request token must be issued to an operator of a business.');
            }

            $sale = $request->getAttribute('sale_id');
            $claims = array_filter([
                $request->business_id,
                $sale === null ? null : DB::table('sales')->where('id', $sale)->value('business_id'),
            ], static fn (mixed $id): bool => $id !== null);

            foreach ($claims as $claim) {
                if ((int) $claim !== (int) $business) {
                    throw new LogicException('A request token cannot span more than one business.');
                }
            }

            $request->business_id = $business;
        });

        static::updating(function (self $request): void {
            if ($request->isDirty('business_id')) {
                throw new LogicException('A request token cannot be moved to another business.');
            }
        });
    }
}
