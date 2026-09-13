<?php

namespace App\Actions\WhatsApp;

use App\Contracts\WhatsAppClient;
use App\Enums\SaleStatus;
use App\Enums\WhatsAppDeliveryOrigin;
use App\Enums\WhatsAppDeliveryStatus;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\WhatsAppDelivery;
use App\Services\AuditLogger;
use App\Support\WhatsAppReceiptEligibility;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Persists an eligible completed Sale's receipt as a pending automatic delivery, to be dispatched
 * later by `inventra:dispatch-whatsapp-receipts`.
 *
 * This runs AFTER the sale transaction has committed and it never calls the provider, so a Meta
 * outage, a missing credential or any unexpected failure here cannot roll back, delay or fail a
 * recorded sale. Every failure path is swallowed and logged: the sale is the record of truth, the
 * receipt is a courtesy.
 */
class QueueAutomaticWhatsAppReceipt
{
    public function __construct(
        private readonly WhatsAppClient $client,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return WhatsAppDelivery|null the queued delivery, or null when the Sale did not qualify
     */
    public function execute(Sale $sale): ?WhatsAppDelivery
    {
        try {
            return $this->queue($sale);
        } catch (Throwable $exception) {
            // Deliberately terminal. The sale is already committed and must not be disturbed.
            Log::warning('Automatic WhatsApp receipt could not be queued for a completed sale.', [
                'sale_id' => $sale->id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function queue(Sale $sale): ?WhatsAppDelivery
    {
        if ($sale->status !== SaleStatus::Completed) {
            return null;
        }

        // Without credentials there is nothing to dispatch to, so queueing would only accumulate
        // rows that can never be sent. The sale itself is unaffected.
        if (! $this->client->isConfigured()) {
            return null;
        }

        $customer = Customer::query()->find($sale->customer_id);

        if ($customer === null || ! WhatsAppReceiptEligibility::permits($customer)) {
            return null;
        }

        return DB::transaction(function () use ($sale, $customer): ?WhatsAppDelivery {
            $lockedSale = Sale::query()->lockForUpdate()->find($sale->id);

            if ($lockedSale === null || $lockedSale->status !== SaleStatus::Completed) {
                return null;
            }

            // A Sale is entitled to exactly one automatic receipt. Any delivery already on record —
            // automatic or a manual send a staff member got in first with — means the customer has
            // been served, so the scheduler must not add another.
            if (WhatsAppDelivery::query()->where('sale_id', $lockedSale->id)->exists()) {
                return null;
            }

            $now = now();
            $delivery = new WhatsAppDelivery;
            $delivery->sale_id = $lockedSale->id;
            $delivery->customer_id = $customer->id;
            $delivery->request_id = (string) Str::uuid();
            $delivery->origin = WhatsAppDeliveryOrigin::Automatic->value;
            $delivery->destination_phone = $customer->phone;
            $delivery->consent_checked_at = $now;
            $delivery->consent_opt_in_at_snapshot = $customer->whatsapp_opt_in_at;
            $delivery->requested_at = $now;
            $delivery->dispatch_claimed_at = null;
            $delivery->status = WhatsAppDeliveryStatus::Pending;
            $delivery->attempt = 1;
            $delivery->created_by = $lockedSale->sold_by;
            $delivery->save();

            $this->audit->record(
                'whatsapp_receipt_auto_queued',
                $delivery,
                null,
                newValues: $delivery->getAttributes(),
                metadata: ['origin' => WhatsAppDeliveryOrigin::Automatic->value],
            );

            return $delivery;
        });
    }
}
