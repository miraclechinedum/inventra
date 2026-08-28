<?php

namespace App\Http\Controllers;

use App\Actions\WhatsApp\TransitionWhatsAppDelivery;
use App\Enums\WhatsAppDeliveryStatus;
use App\Models\WhatsAppDelivery;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
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

    public function handle(Request $request, TransitionWhatsAppDelivery $transition): Response
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

        foreach ($this->statuses($payload) as $statusData) {
            $providerId = $statusData['id'] ?? null;
            $status = $statusData['status'] ?? null;

            if (! is_string($providerId) || ! is_string($status)) {
                continue;
            }

            $deliveryStatus = WhatsAppDeliveryStatus::tryFrom($status);

            if ($deliveryStatus === null || $deliveryStatus === WhatsAppDeliveryStatus::Pending
                || $deliveryStatus === WhatsAppDeliveryStatus::Accepted
                || $deliveryStatus === WhatsAppDeliveryStatus::Unresolved) {
                continue;
            }

            $delivery = WhatsAppDelivery::query()->where('provider_message_id', $providerId)->first();

            if ($delivery === null) {
                continue;
            }

            $timestamp = $statusData['timestamp'] ?? null;
            $occurredAt = now();

            if (is_scalar($timestamp) && ctype_digit((string) $timestamp)) {
                $candidate = (int) $timestamp;

                if ($candidate > 0 && $candidate <= now()->addDay()->timestamp) {
                    $occurredAt = Carbon::createFromTimestampUTC($candidate);
                }
            }
            $error = is_array($statusData['errors'][0] ?? null) ? $statusData['errors'][0] : [];
            $transitioned = $transition->execute(
                $delivery,
                $deliveryStatus,
                $occurredAt,
                failureCode: $deliveryStatus === WhatsAppDeliveryStatus::Failed && is_scalar($error['code'] ?? null)
                    ? (string) $error['code'] : null,
                failureReason: $deliveryStatus === WhatsAppDeliveryStatus::Failed && is_string($error['title'] ?? null)
                    ? $error['title'] : null,
            );

            if ($deliveryStatus === WhatsAppDeliveryStatus::Failed
                && $delivery->status !== WhatsAppDeliveryStatus::Failed
                && $transitioned->status === WhatsAppDeliveryStatus::Failed) {
                Log::warning('WhatsApp provider reported a definitive delivery failure.', [
                    'delivery_id' => $transitioned->id,
                    'sale_id' => $transitioned->sale_id,
                    'failure_code' => $transitioned->failure_code,
                ]);
            }
        }

        return response('EVENT_RECEIVED');
    }

    private function hasValidSignature(string $raw, ?string $signature): bool
    {
        $secret = config('whatsapp.app_secret');

        if (! is_string($secret) || $secret === '' || ! is_string($signature) || ! str_starts_with($signature, 'sha256=')) {
            return false;
        }

        return hash_equals('sha256='.hash_hmac('sha256', $raw, $secret), $signature);
    }

    /** @return array<int, array<string, mixed>> */
    private function statuses(array $payload): array
    {
        $statuses = [];

        foreach (($payload['entry'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            foreach (($entry['changes'] ?? []) as $change) {
                if (! is_array($change) || ! is_array($change['value']['statuses'] ?? null)) {
                    continue;
                }

                foreach ($change['value']['statuses'] as $status) {
                    if (is_array($status)) {
                        $statuses[] = $status;
                    }
                }
            }
        }

        return $statuses;
    }
}
