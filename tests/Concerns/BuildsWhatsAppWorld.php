<?php

namespace Tests\Concerns;

use App\Models\Business;
use App\Models\Customer;
use App\Models\WhatsAppAutomation;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Tenancy\CurrentBusiness;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * WhatsApp fixtures for any Business: its own connection, its own automations and messages.
 * Every credential is a local placeholder; nothing here reaches Meta.
 */
trait BuildsWhatsAppWorld
{
    /**
     * Gives $business its own copy of the four automations. The installation's rows came from the
     * original migration; a Business created later has none until provisioning creates them.
     */
    protected function automationsFor(Business $business): void
    {
        $template = DB::table('whatsapp_automations')->where('business_id', $this->installationBusinessId())->get();

        foreach ($template as $row) {
            DB::table('whatsapp_automations')->insertOrIgnore([
                'business_id' => $business->getKey(), 'key' => $row->key, 'enabled' => false, 'body' => $row->body,
                'template_language' => $row->template_language, 'delay_hours' => $row->delay_hours,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    protected function connectWhatsApp(Business $business, string $waba, string $phoneNumberId, string $token): WhatsAppConnection
    {
        $connection = WhatsAppConnection::forBusiness($business);
        $connection->forceFill([
            'provider' => 'fake',
            'waba_id' => $waba,
            'phone_number_id' => $phoneNumberId,
            'display_phone_number' => '+234 700 000 '.substr($phoneNumberId, -4),
            'access_token' => $token,
            'status' => 'connected',
            'verified_at' => now(),
            'connected_at' => now(),
        ])->save();

        return $connection;
    }

    /** Enables $key for $business with an approved template, as a completed template sync would. */
    protected function approvedAutomation(Business $business, string $key = WhatsAppAutomation::WELCOME): WhatsAppAutomation
    {
        return app(CurrentBusiness::class)->run($business, function () use ($key): WhatsAppAutomation {
            $automation = WhatsAppAutomation::forKey($key);
            $automation->forceFill([
                'enabled' => true,
                'template_name' => 'inventra_'.$key,
                'template_language' => 'en',
                'template_status' => 'APPROVED',
            ])->save();

            return $automation->refresh();
        });
    }

    /** A queued message to a consenting customer of the connection's Business. */
    protected function queuedMessage(WhatsAppConnection $connection, WhatsAppAutomation $automation, string $key): WhatsAppMessage
    {
        $business = Business::query()->findOrFail($connection->business_id);

        return app(CurrentBusiness::class)->run($business, function () use ($business, $connection, $automation, $key): WhatsAppMessage {
            $customer = Customer::factory()->forBusiness($business)->create([
                'phone' => '+23480900'.str_pad((string) (crc32($key) % 100000), 5, '0', STR_PAD_LEFT),
                'is_active' => true,
                'whatsapp_opt_in' => true,
                'whatsapp_opt_in_at' => now(),
            ]);

            $message = new WhatsAppMessage;
            $message->business_id = $business->getKey();
            $message->whatsapp_automation_id = $automation->id;
            $message->whatsapp_connection_id = $connection->id;
            $message->type = $automation->key;
            $message->customer_id = $customer->id;
            $message->recipient_name = $customer->full_name;
            $message->destination_phone = $customer->phone;
            $message->body = 'Hi';
            $message->template_values = ['customer_name' => $customer->full_name, 'business_name' => 'X'];
            $message->idempotency_key = $key;
            $message->origin = 'automatic';
            $message->status = WhatsAppMessage::STATUS_QUEUED;
            $message->queued_at = now();
            $message->attempt = 1;
            $message->save();

            return $message;
        });
    }

    /** A status webhook exactly as Meta shapes it: the WABA as `entry.id`, the number in metadata. */
    protected function whatsappStatusWebhook(string $waba, string $phoneNumberId, string $providerMessageId, string $status, string $secret = 'test-secret'): TestResponse
    {
        $payload = json_encode(['entry' => [['id' => $waba, 'changes' => [['value' => [
            'metadata' => ['phone_number_id' => $phoneNumberId],
            'statuses' => [['id' => $providerMessageId, 'status' => $status]],
        ]]]]]]);

        return $this->call('POST', route('webhooks.whatsapp.handle'), [], [], [], [
            'HTTP_X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $payload, $secret),
            'CONTENT_TYPE' => 'application/json',
        ], $payload);
    }

    private function installationBusinessId(): int
    {
        return (int) DB::table('businesses')->orderBy('id')->value('id');
    }
}
