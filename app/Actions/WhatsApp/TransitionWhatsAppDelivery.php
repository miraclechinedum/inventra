<?php

namespace App\Actions\WhatsApp;

use App\Enums\WhatsAppDeliveryStatus;
use App\Models\WhatsAppDelivery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TransitionWhatsAppDelivery
{
    public function execute(
        WhatsAppDelivery $delivery,
        WhatsAppDeliveryStatus $status,
        ?Carbon $occurredAt = null,
        ?string $providerMessageId = null,
        ?string $failureCode = null,
        ?string $failureReason = null,
    ): WhatsAppDelivery {
        return DB::transaction(function () use ($delivery, $status, $occurredAt, $providerMessageId, $failureCode, $failureReason): WhatsAppDelivery {
            $locked = WhatsAppDelivery::query()->lockForUpdate()->findOrFail($delivery->id);

            if ($status === WhatsAppDeliveryStatus::Unresolved || ! $this->canTransition($locked->status, $status)) {
                return $locked;
            }

            $now = $occurredAt ?? now();
            $changes = ['status' => $status->value, 'updated_at' => now()];

            if ($providerMessageId !== null) {
                $changes['provider_message_id'] = mb_substr($providerMessageId, 0, 255);
            }

            match ($status) {
                WhatsAppDeliveryStatus::Accepted => null,
                WhatsAppDeliveryStatus::Sent => $changes['sent_at'] = $now,
                WhatsAppDeliveryStatus::Delivered => $changes['delivered_at'] = $now,
                WhatsAppDeliveryStatus::Read => $changes['read_at'] = $now,
                WhatsAppDeliveryStatus::Failed => $changes += [
                    'failed_at' => $now,
                    'failure_code' => $this->safeProviderText($failureCode, 64),
                    'failure_reason' => $this->safeProviderText($failureReason, 500),
                ],
                WhatsAppDeliveryStatus::Pending => null,
                WhatsAppDeliveryStatus::Unresolved => null,
            };

            DB::table('whatsapp_deliveries')->where('id', $locked->id)->update($changes);

            return $locked->fresh();
        });
    }

    private function canTransition(WhatsAppDeliveryStatus $current, WhatsAppDeliveryStatus $next): bool
    {
        if ($current === $next) {
            return false;
        }

        if (in_array($current, [WhatsAppDeliveryStatus::Read, WhatsAppDeliveryStatus::Failed, WhatsAppDeliveryStatus::Unresolved], true)) {
            return false;
        }

        if ($next === WhatsAppDeliveryStatus::Failed) {
            return $current->rank() <= WhatsAppDeliveryStatus::Sent->rank();
        }

        return $next !== WhatsAppDeliveryStatus::Pending && $next->rank() > $current->rank();
    }

    private function safeProviderText(?string $value, int $maximumLength): ?string
    {
        if ($value === null) {
            return null;
        }

        foreach (['access_token', 'app_secret', 'verify_token'] as $key) {
            $secret = config('whatsapp.'.$key);

            if (is_string($secret) && $secret !== '') {
                $value = str_replace($secret, '[redacted]', $value);
            }
        }

        return mb_substr($value, 0, $maximumLength);
    }
}
