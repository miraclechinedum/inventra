<?php

namespace App\Actions\WhatsApp;

use App\Contracts\WhatsAppClient;
use App\Enums\SaleStatus;
use App\Enums\WhatsAppDeliveryStatus;
use App\Exceptions\WhatsAppOutcomeUnknownException;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\User;
use App\Models\WhatsAppDelivery;
use App\Services\AuditLogger;
use App\Support\CanonicalLoginIdentifier;
use App\Support\WhatsAppReceiptTemplate;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class SendWhatsAppReceipt
{
    public function __construct(
        private readonly WhatsAppClient $client,
        private readonly TransitionWhatsAppDelivery $transition,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(User $actor, Sale $sale, string $requestId, ?WhatsAppDelivery $retryOf = null): WhatsAppDelivery
    {
        if (! $this->client->isConfigured()) {
            throw ValidationException::withMessages(['whatsapp' => 'WhatsApp receipt delivery is not configured.']);
        }

        [$delivery, $created] = DB::transaction(function () use ($actor, $sale, $requestId, $retryOf): array {
            $lockedSale = Sale::query()->lockForUpdate()->findOrFail($sale->id);
            $existing = WhatsAppDelivery::query()->where('request_id', $requestId)->first();

            if ($existing !== null) {
                if ($existing->sale_id !== $lockedSale->id || $existing->created_by !== $actor->id) {
                    throw ValidationException::withMessages(['request_token' => 'The send request is invalid.']);
                }

                return [$existing, false];
            }

            if ($lockedSale->status !== SaleStatus::Completed) {
                throw ValidationException::withMessages(['sale' => 'Only a completed Sale can be sent.']);
            }

            if ($retryOf !== null && ($retryOf->sale_id !== $lockedSale->id || $retryOf->status !== WhatsAppDeliveryStatus::Failed)) {
                throw ValidationException::withMessages(['delivery' => 'Only a failed attempt for this Sale can be retried.']);
            }

            $customer = Customer::query()->lockForUpdate()->findOrFail($lockedSale->customer_id);
            $this->ensureEligible($customer);
            $now = now();
            $delivery = new WhatsAppDelivery;
            $delivery->sale_id = $lockedSale->id;
            $delivery->customer_id = $customer->id;
            $delivery->request_id = $requestId;
            $delivery->destination_phone = $customer->phone;
            $delivery->consent_checked_at = $now;
            $delivery->consent_opt_in_at_snapshot = $customer->whatsapp_opt_in_at;
            $delivery->requested_at = $now;
            $delivery->status = WhatsAppDeliveryStatus::Pending;
            $delivery->attempt = (int) WhatsAppDelivery::query()->where('sale_id', $lockedSale->id)->max('attempt') + 1;
            $delivery->created_by = $actor->id;
            $delivery->save();
            $this->audit->record(
                $retryOf === null ? 'whatsapp_receipt_requested' : 'whatsapp_receipt_retried',
                $delivery,
                $actor,
                newValues: $delivery->getAttributes(),
            );

            return [$delivery, true];
        });

        if (! $created) {
            return $delivery;
        }

        try {
            $result = $this->client->sendReceipt($delivery, WhatsAppReceiptTemplate::parameters($sale));
        } catch (WhatsAppOutcomeUnknownException) {
            return $this->markOutcomeUnknown($delivery);
        }

        if ($result->accepted) {
            try {
                $delivery = $this->transition->execute(
                    $delivery,
                    WhatsAppDeliveryStatus::Accepted,
                    providerMessageId: $result->providerMessageId,
                );
            } catch (UniqueConstraintViolationException) {
                return $this->markOutcomeUnknown($delivery);
            }
            $this->audit->record('whatsapp_receipt_accepted', $delivery, $actor, newValues: $delivery->getAttributes());

            return $delivery;
        }

        $delivery = $this->transition->execute(
            $delivery,
            WhatsAppDeliveryStatus::Failed,
            failureCode: $result->failureCode,
            failureReason: $result->failureReason,
        );
        $this->audit->record('whatsapp_receipt_failed', $delivery, $actor, newValues: $delivery->getAttributes());
        Log::warning('WhatsApp provider definitively rejected a receipt delivery.', [
            'delivery_id' => $delivery->id,
            'sale_id' => $delivery->sale_id,
            'failure_code' => $delivery->failure_code,
        ]);

        return $delivery;
    }

    private function markOutcomeUnknown(WhatsAppDelivery $delivery): WhatsAppDelivery
    {
        DB::table('whatsapp_deliveries')->where('id', $delivery->id)->update([
            'failure_code' => 'outcome_unknown',
            'failure_reason' => 'Provider acceptance outcome could not be confirmed.',
            'updated_at' => now(),
        ]);
        Log::warning('WhatsApp receipt delivery has an ambiguous provider outcome.', [
            'delivery_id' => $delivery->id,
            'sale_id' => $delivery->sale_id,
            'failure_code' => 'outcome_unknown',
        ]);

        return $delivery->fresh();
    }

    private function ensureEligible(Customer $customer): void
    {
        $canonicalPhone = CanonicalLoginIdentifier::normalizeNigerianPhone($customer->phone);

        if (! $customer->is_active || ! $customer->whatsapp_opt_in || $customer->whatsapp_opt_out_at !== null
            || $customer->whatsapp_opt_in_at === null || $canonicalPhone !== $customer->phone) {
            throw ValidationException::withMessages(['customer' => 'The Customer is not currently eligible for WhatsApp delivery.']);
        }
    }
}
