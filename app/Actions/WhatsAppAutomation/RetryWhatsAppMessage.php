<?php

namespace App\Actions\WhatsAppAutomation;

use App\Models\Customer;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Services\AuditLogger;
use App\Tenancy\CurrentBusiness;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Retries a failed message by creating a NEW attempt. The failed row is never modified, so the
 * history of what was tried and what the provider said stays intact and auditable.
 *
 * Double-click safety is structural rather than advisory: the new row's idempotency key is derived
 * from the original key plus the attempt number, and that column is UNIQUE. Two concurrent retries
 * of the same failed message therefore collide in the database and exactly one new attempt exists.
 * Nothing here does `if (! exists()) create()`.
 *
 * Consent is re-checked at retry time, not inherited. A customer who opted out after the original
 * failure must not be messaged by a retry of it.
 */
class RetryWhatsAppMessage
{
    public function __construct(
        private readonly SendWhatsAppMessage $sender,
        private readonly AuditLogger $audit,
        private readonly CurrentBusiness $tenancy,
    ) {}

    public function execute(User $actor, WhatsAppMessage $message): WhatsAppMessage
    {
        // The route binding is already scoped to the operator's Business; this holds even for a
        // message loaded some other way. Another Business's message is not found, not refused.
        $business = $this->tenancy->forActor($actor);

        if ((int) $message->business_id !== (int) $business->getKey()) {
            throw (new ModelNotFoundException)->setModel(WhatsAppMessage::class, [$message->getKey()]);
        }

        $created = DB::transaction(function () use ($actor, $message, $business): WhatsAppMessage {
            $locked = WhatsAppMessage::query()->where('business_id', $business->getKey())->lockForUpdate()->findOrFail($message->id);

            if ($locked->status !== WhatsAppMessage::STATUS_FAILED) {
                throw ValidationException::withMessages([
                    'message' => 'Only a failed message can be retried.',
                ]);
            }

            // Never retry something a later attempt already succeeded at, or that has already been
            // retried once.
            if (WhatsAppMessage::query()->where('business_id', $business->getKey())->where('retry_of_id', $locked->id)->exists()) {
                throw ValidationException::withMessages([
                    'message' => 'This message has already been retried.',
                ]);
            }

            if ($locked->customer_id !== null) {
                $customer = Customer::query()->where('business_id', $business->getKey())->find($locked->customer_id);

                if ($customer === null || ! WhatsAppAutomationEligibility::permitsCustomer($customer)) {
                    throw ValidationException::withMessages([
                        'message' => 'This customer is no longer eligible to receive WhatsApp messages.',
                    ]);
                }
            }

            if ($locked->user_id !== null) {
                // Only a colleague in the same Business can be re-addressed.
                $user = User::query()->where('business_id', $business->getKey())->find($locked->user_id);

                if ($user === null || WhatsAppAutomationEligibility::staffDestination($user) === null) {
                    throw ValidationException::withMessages([
                        'message' => 'This recipient no longer has a usable WhatsApp number.',
                    ]);
                }
            }

            $attempt = $locked->attempt + 1;

            try {
                $retry = new WhatsAppMessage;
                $retry->business_id = $locked->business_id;
                $retry->whatsapp_automation_id = $locked->whatsapp_automation_id;
                $retry->type = $locked->type;
                $retry->customer_id = $locked->customer_id;
                $retry->user_id = $locked->user_id;
                $retry->subject_type = $locked->subject_type;
                $retry->subject_id = $locked->subject_id;
                $retry->recipient_name = $locked->recipient_name;
                $retry->destination_phone = $locked->destination_phone;
                $retry->body = $locked->body;
                // The UNIQUE key that makes a concurrent double-retry impossible.
                $retry->idempotency_key = $locked->idempotency_key.':retry:'.$attempt;
                $retry->origin = $locked->origin;
                $retry->status = WhatsAppMessage::STATUS_QUEUED;
                $retry->queued_at = now();
                $retry->attempt = $attempt;
                $retry->retry_of_id = $locked->id;
                $retry->created_by = $actor->id;
                $retry->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages([
                    'message' => 'This message has already been retried.',
                ]);
            }

            $this->audit->record('whatsapp_message_retried', $retry, $actor, newValues: [
                'retry_of_id' => $locked->id,
                'attempt' => $attempt,
            ]);

            return $retry;
        });

        return $this->sender->dispatch($created);
    }
}
