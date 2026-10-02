<?php

namespace App\Actions\WhatsAppAutomation;

use App\Models\Customer;
use App\Models\User;
use App\Models\WhatsAppAutomation;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Settings\BusinessSettings;
use App\Subscriptions\Entitlement;
use App\Subscriptions\Entitlements;
use App\Support\CanonicalLoginIdentifier;
use App\Support\WhatsApp\WhatsAppTemplate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * The one place an automatic WhatsApp message is created. Every trigger — welcome, post-purchase,
 * pickup, low stock — goes through here, so consent, eligibility and duplicate prevention are
 * decided once rather than four times.
 *
 * Duplicate prevention is the database's job, not this class's. Each caller supplies a deterministic
 * `idempotencyKey` derived from the event itself, the column is UNIQUE, and a second INSERT is
 * refused by MySQL. That is what makes a browser retry, a duplicated HTTP request, a scheduler
 * overlap, a repeated model save or two concurrent workers all collapse to exactly one message.
 * Note there is no `exists()` check before the insert: such a check is a race, and relying on one
 * is precisely the failure this design avoids.
 *
 * Every message belongs to its automation's Business, and everything it names — the customer, the
 * staff recipient, the subject — must belong to that same Business; a combination spanning two is a
 * programming error and throws rather than queueing. The message is stamped with that Business and
 * captures that Business's own connection, never another's.
 *
 * Delivery failures are swallowed and logged. These calls hang off committed business writes — a
 * created customer, a completed sale, a stock movement — and a WhatsApp problem must never roll
 * back or fail the business record that caused it.
 */
class QueueWhatsAppMessage
{
    public function __construct(
        private readonly BusinessSettings $business,
        private readonly Entitlements $entitlements,
    ) {}

    /**
     * @param  array<string, string>  $values  template variable values
     */
    public function toCustomer(
        WhatsAppAutomation $automation,
        Customer $customer,
        array $values,
        string $idempotencyKey,
        ?Model $subject = null,
        ?\DateTimeInterface $sendAfter = null,
    ): ?WhatsAppMessage {
        if (! $this->readyToSend($automation)) {
            return null;
        }

        // Consent, reusing the rules the old receipt feature established rather than inventing a
        // second interpretation. A customer who never opted in is never messaged.
        if (! WhatsAppAutomationEligibility::permitsCustomer($customer)) {
            return null;
        }

        return $this->persist(
            automation: $automation,
            type: $automation->key,
            recipientName: $customer->full_name,
            phone: (string) $customer->effectiveWhatsAppPhone(),
            values: $values,
            idempotencyKey: $idempotencyKey,
            customer: $customer,
            user: null,
            subject: $subject,
            sendAfter: $sendAfter,
        );
    }

    /**
     * The business's own alert number — the low-stock destination.
     *
     * Not tied to a staff account: this is a number the business configured for itself, so there is
     * no user row to attribute it to and `user_id` stays null. Consent does not apply for the same
     * reason it does not apply to staff — the business is being told about its own stock.
     *
     * The number is re-normalised here rather than trusted. It is stored canonically by the
     * settings request, but this is the last point before a message is addressed, and a value that
     * reached the column by any other route must not become a send to something unexpected.
     *
     * @param  array<string, string>  $values
     */
    public function toBusinessAlertNumber(
        WhatsAppAutomation $automation,
        string $phone,
        array $values,
        string $idempotencyKey,
        ?Model $subject = null,
    ): ?WhatsAppMessage {
        if (! $this->readyToSend($automation)) {
            return null;
        }

        $canonical = CanonicalLoginIdentifier::normalizeNigerianPhone($phone);

        if ($canonical === null) {
            return null;
        }

        return $this->persist(
            automation: $automation,
            type: $automation->key,
            recipientName: $this->business->for($automation->business_id)->business_name,
            phone: $canonical,
            values: $values,
            idempotencyKey: $idempotencyKey,
            customer: null,
            user: null,
            subject: $subject,
            sendAfter: null,
        );
    }

    /**
     * A staff recipient. Consent does not apply: these are colleagues being told about the
     * business's own stock, not customers being marketed to. Eligibility still does, because a
     * number that cannot receive WhatsApp cannot be messaged.
     *
     * Retained deliberately. The low-stock alert no longer uses it — that now goes to the
     * business's `manager_alert_number` — but the path, the pivot behind it and its eligibility
     * rules are kept intact for history and for any future staff-addressed automation.
     *
     * @param  array<string, string>  $values
     */
    public function toStaff(
        WhatsAppAutomation $automation,
        User $user,
        array $values,
        string $idempotencyKey,
        ?Model $subject = null,
    ): ?WhatsAppMessage {
        if (! $this->readyToSend($automation)) {
            return null;
        }

        $phone = WhatsAppAutomationEligibility::staffDestination($user);

        if ($phone === null) {
            return null;
        }

        return $this->persist(
            automation: $automation,
            type: $automation->key,
            recipientName: $user->name,
            phone: $phone,
            values: $values,
            idempotencyKey: $idempotencyKey,
            customer: null,
            user: $user,
            subject: $subject,
            sendAfter: null,
        );
    }

    /**
     * Both gates that decide whether an automatic message may exist at all: the business number must
     * be connected, and the automation must be switched on. The toggle is therefore not cosmetic —
     * turning it off stops future messages being created, here, before anything is queued.
     */
    private function readyToSend(WhatsAppAutomation $automation): bool
    {
        // The plan must include WhatsApp and the subscription must permit it: a Business without the
        // entitlement never queues, so the scheduler has nothing to send on its behalf.
        return $automation->enabled
            && $this->entitlements->allows($automation->business_id, Entitlement::WhatsAppAutomation)
            && WhatsAppConnection::forBusiness($automation->business_id)->isConnected();
    }

    /** The automation's Business, provided nothing the message names belongs to another. */
    private function owningBusiness(WhatsAppAutomation $automation, ?Customer $customer, ?User $user, ?Model $subject): int
    {
        $business = (int) $automation->business_id;
        $named = [
            $customer?->business_id,
            $user?->business_id,
            $subject !== null && array_key_exists('business_id', $subject->getAttributes()) ? $subject->getAttribute('business_id') : null,
        ];

        foreach ($named as $owner) {
            if ($owner !== null && (int) $owner !== $business) {
                throw new LogicException('A WhatsApp message cannot combine records of more than one business.');
            }
        }

        return $business;
    }

    /** @param array<string, string> $values */
    private function persist(
        WhatsAppAutomation $automation,
        string $type,
        string $recipientName,
        string $phone,
        array $values,
        string $idempotencyKey,
        ?Customer $customer,
        ?User $user,
        ?Model $subject,
        ?\DateTimeInterface $sendAfter,
    ): ?WhatsAppMessage {
        $business = $this->owningBusiness($automation, $customer, $user, $subject);
        $body = WhatsAppTemplate::render($automation->key, $automation->body, $values);

        if (trim($body) === '') {
            return null;
        }

        // The connection is captured now, not at dispatch: the message belongs to the sender
        // identity its Business had connected when the event happened.
        $connection = WhatsAppConnection::forBusiness($business);

        try {
            $message = new WhatsAppMessage;
            $message->business_id = $business;
            $message->whatsapp_automation_id = $automation->id;
            $message->whatsapp_connection_id = $connection->id;
            $message->type = $type;
            $message->customer_id = $customer?->id;
            $message->user_id = $user?->id;
            $message->recipient_name = mb_substr($recipientName, 0, 255);
            $message->destination_phone = $phone;
            $message->body = mb_substr($body, 0, WhatsAppTemplate::MAX_LENGTH);
            // Snapshotted for Meta's positional template parameters; the underlying facts may have
            // moved by the time the scheduler sends.
            $message->template_values = $values;
            $message->idempotency_key = $idempotencyKey;
            $message->origin = 'automatic';
            $message->status = WhatsAppMessage::STATUS_QUEUED;
            $message->queued_at = now();
            $message->send_after = $sendAfter;
            $message->attempt = 1;

            if ($subject !== null) {
                $message->subject_type = $subject->getMorphClass();
                $message->subject_id = $subject->getKey();
            }

            $message->save();

            return $message;
        } catch (UniqueConstraintViolationException) {
            // The event already produced this message. Exactly the outcome intended, so this is a
            // success path, not an error: the second caller simply has nothing to do.
            return null;
        } catch (\Throwable $exception) {
            // Structural detail only. A QueryException message carries the failing SQL and its
            // bindings, which here are the recipient's name, number and message body.
            Log::warning('A WhatsApp automation message could not be queued.', [
                'automation' => $automation->key,
                'exception' => $exception::class,
                'code' => $exception->getCode(),
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
            ]);

            return null;
        }
    }
}
