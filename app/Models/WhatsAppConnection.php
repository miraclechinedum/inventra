<?php

namespace App\Models;

use App\Models\Concerns\ScopedToCurrentBusiness;
use App\Tenancy\CurrentBusiness;
use Illuminate\Database\Eloquent\Model;

/**
 * A Business's WhatsApp Business Account connection. One per Business (UNIQUE business_id).
 *
 * The provider identity is global: `waba_id` and `phone_number_id` are each UNIQUE, so no Business
 * can hold, or attach, an account another Business has connected. `meta_business_id` is Meta's own
 * business identifier and is unrelated to the Inventra tenant key `business_id`.
 *
 * There is no "the connection". An authenticated request reads its own Business's with
 * `forCurrentBusiness()`, background work names the Business it acts for with `forBusiness()`, and
 * the public webhook resolves one only from Meta's identifiers with `forProviderAccount()`. None of
 * them falls back to another Business's row.
 *
 * The access token is the security-critical field. It is:
 *   - encrypted at rest by the `encrypted` cast, so a database dump cannot send as the business;
 *   - hidden from array/JSON serialisation, so `toArray()` cannot leak it into a view, a log line,
 *     an audit row or a test snapshot;
 *   - never passed to Blade or Alpine — the page renders `display_phone_number` only.
 */
class WhatsAppConnection extends Model
{
    use ScopedToCurrentBusiness;

    protected $table = 'whatsapp_connection';

    protected $guarded = ['id', 'business_id'];

    /** Neither secret may ever reach serialisation. */
    protected $hidden = ['access_token', 'verification_code_hash'];

    protected function casts(): array
    {
        return [
            // Laravel's encrypted cast: ciphertext at rest, plaintext only in memory when read.
            'access_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'connected_at' => 'datetime',
            'disconnected_at' => 'datetime',
            'verification_expires_at' => 'datetime',
            'verification_last_sent_at' => 'datetime',
            'verification_attempts' => 'integer',
            'verification_sends' => 'integer',
        ];
    }

    /** The signed-in operator's Business's connection, or a disconnected placeholder. */
    public static function forCurrentBusiness(): self
    {
        return self::forBusiness(app(CurrentBusiness::class)->get());
    }

    /**
     * The named Business's connection, or an unsaved disconnected placeholder for it. Reading never
     * creates a row: a Business has a connection only once it has actually connected.
     */
    public static function forBusiness(Business|int $business): self
    {
        $id = (int) ($business instanceof Business ? $business->getKey() : $business);

        return self::acrossBusinesses()->where('business_id', $id)->first()
            ?? (new self)->forceFill(['business_id' => $id, 'singleton_key' => 'whatsapp', 'status' => 'disconnected']);
    }

    /**
     * The connection Meta's identifiers name, for the public webhook, which has no signed-in Business.
     * Deliberately global: the WABA is unique across every Business, so it is the authoritative
     * route to exactly one tenant. Both identifiers must match — a known WABA reporting a number
     * that is not the one connected resolves to nothing.
     */
    public static function forProviderAccount(string $wabaId, string $phoneNumberId): ?self
    {
        return self::acrossBusinesses()
            ->where('waba_id', $wabaId)
            ->where('phone_number_id', $phoneNumberId)
            ->first();
    }

    /**
     * Connected means Meta supplied everything needed to send: the account, the sender number and a
     * live token. The same conditions are enforced by a CHECK constraint, so no code path — here or
     * in a future one — can record a connection that cannot actually send.
     */
    public function isConnected(): bool
    {
        return $this->status === 'connected'
            && $this->verified_at !== null
            && filled($this->waba_id)
            && filled($this->phone_number_id)
            && filled($this->getRawOriginal('access_token'))
            && ! $this->tokenHasExpired();
    }

    public function tokenHasExpired(): bool
    {
        return $this->token_expires_at !== null && $this->token_expires_at->isPast();
    }

    /** What the page shows: Meta's own formatting where available, never the typed number. */
    public function displayNumber(): ?string
    {
        if (filled($this->display_phone_number)) {
            $digits = preg_replace('/\D/', '', (string) $this->display_phone_number);

            return $digits === '' ? $this->display_phone_number : '+'.$digits;
        }

        return $this->phone_number;
    }
}
