<?php

namespace App\Actions\WhatsAppAutomation;

use App\Contracts\WhatsAppConnectionProvider;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Services\AuditLogger;
use App\Tenancy\CurrentBusiness;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Disconnects the Administrator's own Business's WhatsApp, failing closed.
 *
 * In one transaction the connection becomes disconnected and its access token is erased — Inventra
 * can no longer send as the business, whatever happens at Meta — and every message still waiting
 * to be sent is failed with a truthful reason, so reconnecting later can never flush a backlog the
 * operator believed was stopped. Sent history is untouched and stays attributed.
 *
 * The WABA and number stay recorded on the row, still owned by this Business: status updates for
 * messages already sent keep routing to it, and no other Business can claim the account by
 * default. Moving an account to another Business needs a reviewed transfer, not a disconnect.
 *
 * After commit, the app's webhook subscription is removed at Meta with the token held in memory.
 * That is best effort: Meta cannot be required to answer, and the local disconnect already stands.
 */
class DisconnectWhatsApp
{
    public function __construct(
        private readonly WhatsAppConnectionProvider $provider,
        private readonly AuditLogger $audit,
        private readonly CurrentBusiness $tenancy,
    ) {}

    /** @return bool whether a connection was actually disconnected */
    public function execute(User $actor): bool
    {
        $business = $this->tenancy->forActor($actor);

        $released = DB::transaction(function () use ($actor, $business): ?array {
            $connection = WhatsAppConnection::query()->where('business_id', $business->getKey())->lockForUpdate()->first();

            if ($connection === null || $connection->status === 'disconnected') {
                return null;
            }

            $held = [$connection->access_token, $connection->waba_id];
            $now = now();

            $connection->forceFill([
                'status' => 'disconnected',
                'disconnected_at' => $now,
                'access_token' => null,
                'token_expires_at' => null,
                'failure_reason' => null,
            ])->save();

            $cancelled = DB::table('whatsapp_messages')
                ->where('business_id', $business->getKey())
                ->where('status', WhatsAppMessage::STATUS_QUEUED)
                ->whereNull('dispatch_claimed_at')
                ->update([
                    'status' => WhatsAppMessage::STATUS_FAILED,
                    'failed_at' => $now,
                    'failure_code' => 'disconnected',
                    'failure_reason' => 'WhatsApp was disconnected before this message was sent.',
                    'updated_at' => $now,
                ]);

            $this->audit->record('whatsapp_disconnected', $connection, $actor,
                newValues: ['status' => 'disconnected'], metadata: ['item_count' => $cancelled]);

            return $held;
        });

        if ($released === null) {
            return false;
        }

        [$token, $wabaId] = $released;

        if (is_string($token) && $token !== '' && is_string($wabaId) && $wabaId !== ''
            && ! $this->provider->unsubscribeApp($token, $wabaId)) {
            Log::warning('The WhatsApp webhook subscription could not be removed at Meta after disconnecting.', [
                'business_id' => $business->getKey(),
            ]);
        }

        return true;
    }
}
