<?php

namespace App\Actions\WhatsAppAutomation;

use App\Contracts\WhatsAppConnectionProvider;
use App\Models\Business;
use App\Models\WhatsAppAutomation;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Subscriptions\Entitlement;
use App\Subscriptions\Entitlements;
use App\Support\WhatsApp\TemplateBinding;
use App\Support\WhatsApp\WhatsAppTemplate;
use App\Tenancy\CurrentBusiness;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Hands one persisted message to Meta as an approved template, and records what Meta said.
 *
 * Two rules this enforces, both from the architecture audit:
 *
 *  - Business-initiated messages go as `type: template` with an APPROVED template, never as
 *    free-form text. A message whose automation has no approved template fails here with a
 *    truthful reason rather than being sent in a form Meta would reject.
 *  - The sender is the message's own Business's connection. `sendTemplate()` takes the connection,
 *    so the number and token used are that connection's — one business can never send as another.
 *    The whole send runs inside the message's Business, entered explicitly, so the automation and
 *    template it reads are that Business's too; a message named in another Business's request
 *    fails closed rather than borrowing that request's context.
 *
 * The status written is only ever `sent` or `failed`. `delivered` and `read` come from webhooks:
 * an accepted API request is not a delivered message, and saying otherwise would be a lie told to
 * an operator about a real customer's message.
 */
class SendWhatsAppMessage
{
    public function __construct(
        private readonly WhatsAppConnectionProvider $provider,
        private readonly CurrentBusiness $tenancy,
        private readonly Entitlements $entitlements,
    ) {}

    public function dispatch(WhatsAppMessage $message): WhatsAppMessage
    {
        $business = Business::query()->findOrFail($message->business_id);

        return $this->tenancy->run($business, fn (): WhatsAppMessage => $this->send($message, $business));
    }

    private function send(WhatsAppMessage $message, Business $business): WhatsAppMessage
    {
        // Only this Business's connection — unique per Business, and the composite key holds any
        // connection a message names to that same Business — never one connected elsewhere.
        // Checked at the moment of sending, so a message queued before the plan or subscription
        // changed is never sent on the Business's behalf — by the scheduler or by a retry.
        if (! $this->entitlements->allows($business, Entitlement::WhatsAppAutomation)) {
            return $this->fail($message, 'not_entitled', 'WhatsApp automation is not available on the current plan.');
        }

        $connection = WhatsAppConnection::forBusiness($business);

        if ($connection === null || ! $connection->isConnected()) {
            return $this->fail($message, 'not_connected', 'This WhatsApp connection needs attention.');
        }

        $automation = WhatsAppAutomation::query()->find($message->whatsapp_automation_id);

        if ($automation === null) {
            return $this->fail($message, 'no_automation', 'This message has no automation to send as.');
        }

        $binding = TemplateBinding::for($automation);

        if (! $binding->sendable) {
            return $this->fail($message, 'template_unavailable', $binding->reason ?? 'The selected message template is not approved yet.');
        }

        $attempt = $this->provider->sendTemplate(
            $connection,
            $message->destination_phone,
            (string) $binding->name,
            $binding->language,
            $binding->parameters($message->template_values ?? []),
        );

        $now = now();
        $common = [
            'provider' => $this->provider->name(),
            // Snapshot of the number this actually left from, so webhook routing can be checked
            // against what was used even if the connection is later re-pointed.
            'sender_phone_number_id' => $connection->phone_number_id,
            'whatsapp_connection_id' => $connection->id,
            'updated_at' => $now,
        ];

        if ($attempt->outcomeUnknown) {
            // Never marked failed: the message may well have gone out, and a retry would then send
            // it twice.
            DB::table('whatsapp_messages')->where('business_id', $message->business_id)->where('id', $message->id)->update($common + [
                'status' => WhatsAppMessage::STATUS_SENT,
                'sent_at' => $now,
                'failure_code' => 'outcome_unknown',
                'failure_reason' => $attempt->failureReason,
            ]);
            Log::warning('A WhatsApp message has an ambiguous provider outcome.', [
                'message_id' => $message->id, 'type' => $message->type,
            ]);

            return $message->refresh();
        }

        if ($attempt->accepted) {
            try {
                DB::table('whatsapp_messages')->where('business_id', $message->business_id)->where('id', $message->id)->update($common + [
                    'status' => WhatsAppMessage::STATUS_SENT,
                    'sent_at' => $now,
                    'provider_message_id' => $attempt->providerMessageId,
                    'failure_code' => null,
                    'failure_reason' => null,
                ]);
            } catch (UniqueConstraintViolationException) {
                // A provider id already on another row: ambiguous, so treated the same way and
                // never silently resent.
                DB::table('whatsapp_messages')->where('business_id', $message->business_id)->where('id', $message->id)->update($common + [
                    'status' => WhatsAppMessage::STATUS_SENT,
                    'sent_at' => $now,
                    'failure_code' => 'outcome_unknown',
                    'failure_reason' => 'The provider returned a duplicate message identifier.',
                ]);
            }

            return $message->refresh();
        }

        DB::table('whatsapp_messages')->where('business_id', $message->business_id)->where('id', $message->id)->update($common + [
            'status' => WhatsAppMessage::STATUS_FAILED,
            'failed_at' => $now,
            'failure_code' => $attempt->failureCode,
            'failure_reason' => $attempt->failureReason,
        ]);

        // The failure code only — never the body, never a credential.
        Log::warning('The WhatsApp provider rejected a message.', [
            'message_id' => $message->id, 'type' => $message->type, 'failure_code' => $attempt->failureCode,
        ]);

        return $message->refresh();
    }

    /** Renders the message body for the log and preview, from the same allowlisted engine. */
    public static function renderBody(WhatsAppMessage $message): string
    {
        $automation = $message->automation;

        return $automation === null ? $message->body : WhatsAppTemplate::render(
            $automation->key, $automation->body, $message->template_values ?? []
        );
    }

    private function fail(WhatsAppMessage $message, string $code, string $reason): WhatsAppMessage
    {
        DB::table('whatsapp_messages')->where('business_id', $message->business_id)->where('id', $message->id)->update([
            'status' => WhatsAppMessage::STATUS_FAILED,
            'failed_at' => now(),
            'failure_code' => $code,
            'failure_reason' => $reason,
            'updated_at' => now(),
        ]);

        return $message->refresh();
    }
}
