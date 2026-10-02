<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Tenancy\CurrentBusiness;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use JsonException;

class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request): Response
    {
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');
        $configured = config('whatsapp.verify_token');

        if ($mode !== 'subscribe' || ! is_string($token) || ! is_string($challenge)
            || ! is_string($configured) || $configured === '' || ! hash_equals($configured, $token)) {
            abort(403);
        }

        return response($challenge, 200)->header('Content-Type', 'text/plain');
    }

    public function handle(Request $request): Response
    {
        $raw = $request->getContent();

        if (! $this->hasValidSignature($raw, $request->header('X-Hub-Signature-256'))) {
            abort(403);
        }

        try {
            $payload = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return response('Invalid JSON', 422);
        }

        if (! is_array($payload)) {
            return response('Invalid payload', 422);
        }

        // Resolved once per account and number for this request only: the controller instance can
        // outlive a request, so it must never carry a tenant's connection into the next one.
        $connections = [];

        foreach ($this->statuses($payload) as [$statusData, $wabaId, $phoneNumberId]) {
            // Tenant routing comes from Meta's identifiers alone — never a session, a parameter or
            // a default. The WABA and the number together name exactly one Business's connection;
            // an unknown account, or a known account reporting a number it does not have connected,
            // resolves to nothing and writes nothing. Meta is still acknowledged below, so it does
            // not retry an event that can never apply.
            $connection = $wabaId === null || $phoneNumberId === null ? null
                : $connections[$wabaId.'|'.$phoneNumberId] ??= WhatsAppConnection::forProviderAccount($wabaId, $phoneNumberId);

            if ($connection === null) {
                continue;
            }

            app(CurrentBusiness::class)->run(
                Business::query()->findOrFail($connection->business_id),
                fn () => $this->applyStatus($statusData, $connection),
            );
        }

        return response('EVENT_RECEIVED');
    }

    /** One status event, inside the connection's own Business. */
    private function applyStatus(array $statusData, WhatsAppConnection $connection): void
    {
        $providerId = $statusData['id'] ?? null;
        $status = $statusData['status'] ?? null;

        if (! is_string($providerId) || ! is_string($status)) {
            return;
        }

        // Meta's vocabulary mapped onto ours. Anything unrecognised is ignored rather than
        // guessed at: an unknown status must never be rendered as a delivery claim.
        $mapped = match ($status) {
            'sent' => WhatsAppMessage::STATUS_SENT,
            'delivered' => WhatsAppMessage::STATUS_DELIVERED,
            'read' => WhatsAppMessage::STATUS_READ,
            'failed' => WhatsAppMessage::STATUS_FAILED,
            default => null,
        };

        if ($mapped === null) {
            return;
        }

        // Only this Business's messages are candidates, whatever the id says. provider_message_id
        // is UNIQUE and a retry is a separate row with its own id, so a late webhook for attempt 1
        // can never be applied to attempt 2.
        $message = WhatsAppMessage::query()
            ->where('business_id', $connection->business_id)
            ->where('provider_message_id', $providerId)
            ->first();

        if ($message === null) {
            return;
        }

        // Defence in depth: the message must have left from the number, and through the
        // connection, that this event reports. A misrouted or forged payload therefore cannot
        // walk a message forward even within its own Business.
        if (($message->sender_phone_number_id !== null && $message->sender_phone_number_id !== $connection->phone_number_id)
            || ($message->whatsapp_connection_id !== null && (int) $message->whatsapp_connection_id !== (int) $connection->id)) {
            return;
        }

        $occurredAt = $this->occurredAt($statusData);

        // Idempotent and replay-safe by rank: a status may only move forward. A duplicated or
        // out-of-order webhook — Meta retries, and `read` can arrive before `delivered` — can
        // therefore never walk a message backwards, and re-delivering the same event twice
        // changes nothing the second time.
        if ($this->rank($mapped) <= $this->rank($message->status) && $message->status !== WhatsAppMessage::STATUS_QUEUED) {
            return;
        }

        $update = ['status' => $mapped, 'updated_at' => now()];

        if ($mapped === WhatsAppMessage::STATUS_SENT && $message->sent_at === null) {
            $update['sent_at'] = $occurredAt;
        }

        if ($mapped === WhatsAppMessage::STATUS_DELIVERED) {
            $update['delivered_at'] = $occurredAt;
        }

        if ($mapped === WhatsAppMessage::STATUS_READ) {
            $update['read_at'] = $occurredAt;
        }

        if ($mapped === WhatsAppMessage::STATUS_FAILED) {
            $error = is_array($statusData['errors'][0] ?? null) ? $statusData['errors'][0] : [];
            $update['failed_at'] = $occurredAt;
            $update['failure_code'] = is_scalar($error['code'] ?? null) ? mb_substr((string) $error['code'], 0, 64) : null;
            $update['failure_reason'] = is_string($error['title'] ?? null) ? mb_substr($error['title'], 0, 500) : null;
        }

        DB::table('whatsapp_messages')->where('business_id', $message->business_id)->where('id', $message->id)->update($update);
    }

    /** Later statuses outrank earlier ones; `failed` is terminal and outranks everything. */
    private function rank(string $status): int
    {
        return match ($status) {
            WhatsAppMessage::STATUS_QUEUED => 0,
            WhatsAppMessage::STATUS_SENT => 1,
            WhatsAppMessage::STATUS_DELIVERED => 2,
            WhatsAppMessage::STATUS_READ => 3,
            WhatsAppMessage::STATUS_FAILED => 4,
            default => 0,
        };
    }

    /** The provider's timestamp when it is sane, otherwise now. Never a future date. */
    private function occurredAt(array $statusData): Carbon
    {
        $timestamp = $statusData['timestamp'] ?? null;

        if (is_scalar($timestamp) && ctype_digit((string) $timestamp)) {
            $candidate = (int) $timestamp;

            if ($candidate > 0 && $candidate <= now()->addDay()->timestamp) {
                return Carbon::createFromTimestampUTC($candidate);
            }
        }

        return now();
    }

    private function hasValidSignature(string $raw, ?string $signature): bool
    {
        $secret = config('whatsapp.app_secret');

        if (! is_string($secret) || $secret === '' || ! is_string($signature) || ! str_starts_with($signature, 'sha256=')) {
            return false;
        }

        return hash_equals('sha256='.hash_hmac('sha256', $raw, $secret), $signature);
    }

    /**
     * Each status paired with the WhatsApp Business Account and phone number id it arrived for,
     * which together route it to exactly one Business's connection.
     *
     * @return array<int, array{0: array<string, mixed>, 1: string|null, 2: string|null}>
     */
    private function statuses(array $payload): array
    {
        $statuses = [];
        $entries = $payload['entry'] ?? [];

        // `?? []` only covers an absent key. A present-but-scalar `entry` or `changes` is well
        // within what an authenticated caller can send, and iterating one raises a TypeError —
        // which would answer Meta with a 500 and, after enough retries, let it disable the
        // subscription. Every other level here already checks its shape; these two match.
        if (! is_array($entries)) {
            return $statuses;
        }

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $changes = $entry['changes'] ?? [];

            if (! is_array($changes)) {
                continue;
            }

            // Meta's `entry.id` is the WhatsApp Business Account the changes belong to.
            $wabaId = is_string($entry['id'] ?? null) && $entry['id'] !== '' ? $entry['id'] : null;

            foreach ($changes as $change) {
                if (! is_array($change) || ! is_array($change['value']['statuses'] ?? null)) {
                    continue;
                }

                $number = data_get($change, 'value.metadata.phone_number_id');
                $number = is_string($number) && $number !== '' ? $number : null;

                foreach ($change['value']['statuses'] as $status) {
                    if (is_array($status)) {
                        $statuses[] = [$status, $wabaId, $number];
                    }
                }
            }
        }

        return $statuses;
    }
}
