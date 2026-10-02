<?php

namespace App\Actions\WhatsAppAutomation;

use App\Enums\PaymentStatus;
use App\Enums\SaleStatus;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\WhatsAppAutomation;
use App\Settings\BusinessSettings;
use App\Support\Money;
use App\Support\Quantity;
use App\Tenancy\CurrentBusiness;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Where domain events become WhatsApp messages.
 *
 * Every method here is called AFTER the business write it reacts to has committed, and every one
 * returns quietly when its automation is off, the business number is not connected, or the subject
 * is not eligible. Nothing in this class can fail a sale, a customer record or a stock movement.
 *
 * Each trigger supplies a deterministic idempotency key, and the UNIQUE index on that column is
 * what actually prevents duplicates — see QueueWhatsAppMessage.
 *
 * Every trigger runs inside its subject's own Business, entered explicitly — never whatever context
 * an observer or a command happens to be running in. The automation, the settings, the connection
 * and the low-stock episode are therefore all that Business's own. Any failure, including a subject
 * that disagrees with the signed-in operator's Business, is logged and dropped: the business write
 * that caused it has already committed and is authoritative.
 */
class WhatsAppAutomationTriggers
{
    public function __construct(
        private readonly QueueWhatsAppMessage $queue,
        private readonly BusinessSettings $business,
        private readonly CurrentBusiness $tenancy,
    ) {}

    /**
     * A new customer was created.
     *
     * Keyed on the customer, so the greeting is once per customer for all time: a duplicated form
     * submission, a retried request or a re-saved model cannot produce a second welcome.
     */
    public function customerCreated(Customer $customer): void
    {
        $this->within($customer, fn (Business $business) => $this->welcome($customer, $business));
    }

    private function welcome(Customer $customer, Business $business): void
    {
        $automation = WhatsAppAutomation::forKey(WhatsAppAutomation::WELCOME);

        if ($automation === null) {
            return;
        }

        $this->queue->toCustomer(
            automation: $automation,
            customer: $customer,
            values: [
                'customer_name' => $customer->full_name,
                'business_name' => $this->businessName($business),
            ],
            idempotencyKey: 'welcome:'.$customer->id,
            subject: $customer,
        );
    }

    /**
     * A sale reached the paid state.
     *
     * "Paid" is `payment_status === Paid` on a `Completed` sale — the actual paid state, not merely
     * a recorded sale. A partial, unpaid or voided sale therefore sends nothing, which is the
     * difference between this and the receipt behaviour it replaces.
     *
     * Keyed on the sale, so this fires on the *transition* rather than on the condition: editing a
     * paid sale, recording another payment against it, or re-saving it cannot produce a second
     * message, because the key is already taken.
     */
    public function salePaid(Sale $sale): void
    {
        $this->within($sale, fn (Business $business) => $this->postPurchase($sale, $business));
    }

    private function postPurchase(Sale $sale, Business $business): void
    {
        if ($sale->status !== SaleStatus::Completed || $sale->payment_status !== PaymentStatus::Paid) {
            return;
        }

        // A walk-in has no customer to message.
        if ($sale->isWalkIn() || $sale->customer_id === null) {
            return;
        }

        $automation = WhatsAppAutomation::forKey(WhatsAppAutomation::POST_PURCHASE);
        $customer = Customer::query()->find($sale->customer_id);

        if ($automation === null || $customer === null) {
            return;
        }

        $this->queue->toCustomer(
            automation: $automation,
            customer: $customer,
            values: [
                'customer_name' => $customer->full_name,
                'business_name' => $this->businessName($business),
                // Decimal strings through the existing helper. No floating-point money anywhere.
                'sale_total' => Money::format($sale->total_amount),
                'balance_due' => Money::format($sale->balance_due),
            ],
            idempotencyKey: 'post_purchase:'.$sale->id,
            subject: $sale,
        );
    }

    /**
     * An order was marked ready for collection.
     *
     * The reminder is scheduled rather than sent: `send_after` is the readiness moment plus the
     * automation's configured delay, and the dispatcher simply does not consider the row until then.
     * That is how "1 day after" is honoured on shared hosting, with no daemon and no delayed queue.
     */
    public function saleReadyForPickup(Sale $sale): void
    {
        $this->within($sale, fn (Business $business) => $this->pickupReminder($sale, $business));
    }

    private function pickupReminder(Sale $sale, Business $business): void
    {
        if ($sale->status !== SaleStatus::Completed || $sale->pickup_ready_at === null) {
            return;
        }

        if ($sale->isWalkIn() || $sale->customer_id === null) {
            return;
        }

        $automation = WhatsAppAutomation::forKey(WhatsAppAutomation::PICKUP_REMINDER);
        $customer = Customer::query()->find($sale->customer_id);

        if ($automation === null || $customer === null) {
            return;
        }

        $this->queue->toCustomer(
            automation: $automation,
            customer: $customer,
            values: [
                'customer_name' => $customer->full_name,
                'business_name' => $this->businessName($business),
            ],
            idempotencyKey: 'pickup:'.$sale->id,
            subject: $sale,
            sendAfter: $sale->pickup_ready_at->copy()->addHours(max(0, (int) ($automation->delay_hours ?? 0))),
        );
    }

    /**
     * Stock moved. Fires only on a downward CROSSING, and re-arms only when stock recovers.
     *
     * The episode is what makes this a crossing rather than a condition. Opening one is a UNIQUE
     * insert on `open_key`, so two concurrent stock movements contend in the database and exactly
     * one opens it — the alert is then keyed to that episode id, and no further alert is possible
     * until stock rises back above the reorder level and closes it.
     *
     * Without this the manager would be messaged on every sale of an already-low product.
     */
    public function stockChanged(Product $product): void
    {
        $this->within($product, fn (Business $business) => $this->lowStock($product, $business));
    }

    private function lowStock(Product $product, Business $business): void
    {
        $automation = WhatsAppAutomation::forKey(WhatsAppAutomation::LOW_STOCK);

        if ($automation === null) {
            return;
        }

        // Decimal-string comparison via bccomp: stock and reorder level are decimal(15,3).
        $isLow = bccomp((string) $product->current_stock, (string) $product->reorder_level, 3) <= 0;
        $open = DB::table('whatsapp_low_stock_episodes')
            ->where('business_id', $business->getKey())
            ->where('product_id', $product->id)
            ->whereNull('closed_at')
            ->first();

        if (! $isLow) {
            // Recovered: close the episode, re-arming the alert for a future crossing. `open_key`
            // is cleared so the partial-unique invariant releases.
            if ($open !== null) {
                DB::table('whatsapp_low_stock_episodes')
                    ->where('business_id', $business->getKey())
                    ->where('id', $open->id)
                    ->whereNull('closed_at')
                    ->update(['closed_at' => now(), 'open_key' => null, 'updated_at' => now()]);
            }

            return;
        }

        if ($open !== null) {
            // Still low, and already alerted for this episode. Nothing to do — this is the branch
            // that stops repeated alerts.
            return;
        }

        try {
            $episodeId = DB::table('whatsapp_low_stock_episodes')->insertGetId([
                'business_id' => $business->getKey(),
                'product_id' => $product->id,
                'open_key' => $product->id,
                'stock_at_crossing' => $product->current_stock,
                'reorder_level_at_crossing' => $product->reorder_level,
                'opened_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Another movement opened the episode first; it owns the alert.
            return;
        }

        // The business's own alert number is the destination, and the only one. The automation's
        // staff-recipient pivot is deliberately not read: it and this field would otherwise be two
        // editable answers to the same question, able to disagree and to double-send. The pivot,
        // its relation and its eligibility rules are all left in place for history.
        //
        // Null or unset is a real answer — the business has not nominated a number — so nothing is
        // queued. The episode still stands, so this is not retried until stock recovers and crosses
        // again, and no fake or fallback destination is invented to fill the gap.
        $alertNumber = $this->business->for($business)->manager_alert_number;

        if (! is_string($alertNumber) || trim($alertNumber) === '') {
            return;
        }

        $this->queue->toBusinessAlertNumber(
            automation: $automation,
            phone: $alertNumber,
            values: [
                'product_name' => $product->name,
                'stock_left' => Quantity::trim((string) $product->current_stock),
                'reorder_level' => Quantity::trim((string) $product->reorder_level),
            ],
            // Keyed to the episode alone: one alert per crossing, whatever else happens. The old
            // key carried a recipient id, so this shape also cannot collide with historical rows.
            idempotencyKey: 'low_stock:'.$product->id.':'.$episodeId,
            subject: $product,
        );
    }

    private function businessName(Business $business): string
    {
        return $this->business->for($business)->business_name;
    }

    /** Runs $work inside $subject's own Business. Never throws: see the class notes. */
    private function within(Model $subject, Closure $work): void
    {
        try {
            $business = Business::query()->find($subject->getAttribute('business_id'));

            if ($business === null || ! $business->isActive()) {
                return;
            }

            $this->tenancy->run($business, $work);
        } catch (Throwable $exception) {
            // Structural detail only: a QueryException message carries names, numbers and bodies.
            Log::warning('A WhatsApp automation trigger was skipped.', [
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
                'exception' => $exception::class,
                'code' => $exception->getCode(),
            ]);
        }
    }
}
