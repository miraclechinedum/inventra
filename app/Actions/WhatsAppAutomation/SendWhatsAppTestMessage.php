<?php

namespace App\Actions\WhatsAppAutomation;

use App\Models\User;
use App\Models\WhatsAppAutomation;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Settings\BusinessSettings;
use App\Subscriptions\Entitlement;
use App\Subscriptions\Entitlements;
use App\Support\WhatsApp\OnboardingFailure;
use App\Support\WhatsApp\TemplateBinding;
use App\Support\WhatsApp\WhatsAppTemplate;
use App\Tenancy\CurrentBusiness;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * "Send test to me" — a real send, to the signed-in Administrator only.
 *
 * Three properties matter here:
 *
 *  - It can only ever reach the actor's own number. The destination is resolved from the signed-in
 *    user, never from the request, so no payload can redirect a test at a customer.
 *  - It renders with the same sample values the preview uses, so nothing real is disclosed.
 *  - It is logged with origin `test` and type `test`, which keeps it out of the automatic-event
 *    history while remaining fully auditable.
 *
 * Rate limiting is applied by the controller (per-user throttle); this action additionally refuses
 * when there is no eligible number, rather than reporting a success that never happened.
 */
class SendWhatsAppTestMessage
{
    public function __construct(
        private readonly SendWhatsAppMessage $sender,
        private readonly BusinessSettings $business,
        private readonly CurrentBusiness $tenancy,
        private readonly Entitlements $entitlements,
    ) {}

    public function execute(User $actor, WhatsAppAutomation $automation, ?string $draftBody = null): WhatsAppMessage
    {
        // The operator's own Business: its automation, its identity, its connection. Another
        // Business's automation is not found, whatever route it arrived through.
        $business = $this->tenancy->forActor($actor);

        if ((int) $automation->business_id !== (int) $business->getKey()) {
            throw (new ModelNotFoundException)->setModel(WhatsAppAutomation::class, [$automation->getKey()]);
        }

        if (! $this->entitlements->allows($business, Entitlement::WhatsAppAutomation)) {
            throw ValidationException::withMessages(['test' => OnboardingFailure::NotEntitled->message()]);
        }

        $connection = WhatsAppConnection::forBusiness($business);

        if (! $connection->isConnected()) {
            throw ValidationException::withMessages([
                'test' => 'Connect the business WhatsApp number before sending a test.',
            ]);
        }

        $destination = WhatsAppAutomationEligibility::staffDestination($actor);

        if ($destination === null) {
            throw ValidationException::withMessages([
                'test' => 'Add a valid WhatsApp number to your staff profile before sending a test message to yourself.',
            ]);
        }

        // The unsaved editor text may be tested, but it is validated against the same allowlist as
        // a save, so a test cannot be used to render a token the editor would have refused.
        $body = $draftBody !== null && trim($draftBody) !== '' ? trim($draftBody) : $automation->body;

        if (mb_strlen($body) > WhatsAppTemplate::MAX_LENGTH
            || WhatsAppTemplate::unknownTokensFor($automation->key, $body) !== []) {
            throw ValidationException::withMessages([
                'test' => 'This message uses details that are not available here, so it cannot be tested.',
            ]);
        }

        $sample = WhatsAppTemplate::sampleValues($this->business->for($business)->business_name);
        $rendered = WhatsAppTemplate::render($automation->key, $body, $sample);

        // A test is a real Meta send, so it is subject to the same rule as any other
        // business-initiated message: only an approved template may go out.
        $binding = TemplateBinding::for($automation);

        if (! $binding->sendable) {
            throw ValidationException::withMessages([
                'test' => $binding->reason ?? 'The selected message template is not approved yet.',
            ]);
        }

        $message = DB::transaction(function () use ($actor, $automation, $connection, $destination, $rendered, $sample): WhatsAppMessage {
            $message = new WhatsAppMessage;
            $message->business_id = $connection->business_id;
            $message->whatsapp_automation_id = $automation->id;
            $message->whatsapp_connection_id = $connection->id;
            $message->type = 'test';
            $message->user_id = $actor->id;
            $message->recipient_name = $actor->name;
            $message->destination_phone = $destination;
            $message->body = $rendered;
            $message->template_values = $sample;
            // Unique per send, so repeated tests are each recorded rather than collapsing — while
            // a double-clicked single send is stopped by the controller's throttle.
            $message->idempotency_key = 'test:'.$automation->key.':'.$actor->id.':'.Str::uuid();
            $message->origin = 'test';
            $message->status = WhatsAppMessage::STATUS_QUEUED;
            $message->queued_at = now();
            $message->attempt = 1;
            $message->created_by = $actor->id;
            $message->save();

            return $message;
        });

        // Sent inline so the Administrator gets an immediate, truthful answer.
        return $this->sender->dispatch($message);
    }
}
