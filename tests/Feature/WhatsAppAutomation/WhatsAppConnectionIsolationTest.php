<?php

namespace Tests\Feature\WhatsAppAutomation;

use App\Actions\WhatsAppAutomation\SendWhatsAppMessage;
use App\Contracts\WhatsAppConnectionProvider;
use App\Models\Business;
use App\Models\WhatsAppAutomation;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsWhatsAppWorld;
use Tests\Fakes\FakeWhatsAppProvider;
use Tests\TestCase;

/**
 * Sender isolation.
 *
 * The architecture audit's most serious finding was that the sender came from the environment, so
 * every connected business's messages left the same number regardless of what the page claimed was
 * connected. These tests exist to make that failure impossible to reintroduce: a message's sender
 * identity comes from its own connection, and a webhook for one business's number can never move
 * another business's message.
 */
class WhatsAppConnectionIsolationTest extends TestCase
{
    use BuildsWhatsAppWorld, RefreshDatabase;

    private FakeWhatsAppProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame(
            'inventra_test',
            DB::connection()->getDatabaseName(),
            'Refusing to run: these tests write, and this is not the test database.'
        );

        $this->provider = new FakeWhatsAppProvider;
        $this->app->instance(WhatsAppConnectionProvider::class, $this->provider);
    }

    private function installation(): Business
    {
        return Business::query()->orderBy('id')->firstOrFail();
    }

    private function connection(string $waba, string $phoneNumberId, string $token, ?Business $business = null): WhatsAppConnection
    {
        return $this->connectWhatsApp($business ?? $this->installation(), $waba, $phoneNumberId, $token);
    }

    private function welcome(): WhatsAppAutomation
    {
        return $this->approvedAutomation($this->installation());
    }

    private function queued(WhatsAppConnection $connection, WhatsAppAutomation $automation, string $key): WhatsAppMessage
    {
        return $this->queuedMessage($connection, $automation, $key);
    }

    /**
     * Each message leaves from its OWN connection's number, under its OWN token.
     *
     * This is the test the previous architecture could not have passed: the sender was read from
     * config, so both messages would have gone out identically.
     */
    public function test_each_connection_sends_with_its_own_number_and_token(): void
    {
        $bravo = Business::factory()->create(['name' => 'Bravo Hardware']);
        $this->automationsFor($bravo);
        $a = $this->connection('100000000000001', 'PHONE_A', 'TOKEN_A');
        $b = $this->connection('100000000000002', 'PHONE_B', 'TOKEN_B', $bravo);

        $sender = app(SendWhatsAppMessage::class);
        $sender->dispatch($this->queued($a, $this->welcome(), 'iso:a'));
        $sender->dispatch($this->queued($b, $this->approvedAutomation($bravo), 'iso:b'));

        $this->assertCount(2, $this->provider->sent);

        [$first, $second] = $this->provider->sent;
        $this->assertSame('PHONE_A', $first['phone_number_id']);
        $this->assertSame('TOKEN_A', $first['token']);
        $this->assertSame('PHONE_B', $second['phone_number_id']);
        $this->assertSame('TOKEN_B', $second['token']);

        // No crossing in either direction.
        $this->assertNotSame($first['token'], $second['token']);
        $this->assertNotSame($first['phone_number_id'], $second['phone_number_id']);
    }

    /** The sender is recorded on the message, so routing can be checked after the fact. */
    public function test_the_sending_number_is_recorded_on_the_message(): void
    {
        $automation = $this->welcome();
        $a = $this->connection('100000000000001', 'PHONE_A', 'TOKEN_A');

        $message = app(SendWhatsAppMessage::class)->dispatch($this->queued($a, $automation, 'iso:record'));

        $this->assertSame('PHONE_A', $message->sender_phone_number_id);
        $this->assertSame($a->id, $message->whatsapp_connection_id);
    }

    /** Nothing sends from a connection that is not actually connected. */
    public function test_a_message_without_a_live_connection_fails_truthfully(): void
    {
        $automation = $this->welcome();
        $a = $this->connection('100000000000001', 'PHONE_A', 'TOKEN_A');
        $message = $this->queued($a, $automation, 'iso:dead');

        // The connection goes away between queueing and dispatch.
        DB::table('whatsapp_connection')->where('id', $a->id)->update([
            'status' => 'disconnected', 'verified_at' => null,
        ]);

        $sent = app(SendWhatsAppMessage::class)->dispatch($message);

        $this->assertSame(WhatsAppMessage::STATUS_FAILED, $sent->status);
        $this->assertSame('not_connected', $sent->failure_code);
        $this->assertCount(0, $this->provider->sent);
    }

    // ── Webhook isolation ───────────────────────────────────────────────────────────────────────

    private function webhook(string $providerMessageId, string $phoneNumberId, string $status, string $waba = '100000000000001'): TestResponse
    {
        return $this->whatsappStatusWebhook($waba, $phoneNumberId, $providerMessageId, $status);
    }

    /**
     * A webhook naming a different business's number must not move this message.
     *
     * Provider message ids are opaque; without the phone-number check, a forged or misrouted event
     * carrying a known id could mark another business's message delivered.
     */
    public function test_a_webhook_for_another_number_cannot_move_this_message(): void
    {
        config(['whatsapp.app_secret' => 'test-secret']);
        $automation = $this->welcome();
        $a = $this->connection('100000000000001', 'PHONE_A', 'TOKEN_A');

        $message = app(SendWhatsAppMessage::class)->dispatch($this->queued($a, $automation, 'iso:hook'));
        $this->assertSame(WhatsAppMessage::STATUS_SENT, $message->status);

        // Same provider message id, but PHONE_B claims it.
        $this->webhook((string) $message->provider_message_id, 'PHONE_B', 'delivered')->assertOk();

        $this->assertSame(WhatsAppMessage::STATUS_SENT, $message->refresh()->status, 'a foreign number must not advance this message');
        $this->assertNull($message->delivered_at);

        // The message's own number does advance it.
        $this->webhook((string) $message->provider_message_id, 'PHONE_A', 'delivered')->assertOk();

        $this->assertSame(WhatsAppMessage::STATUS_DELIVERED, $message->refresh()->status);
        $this->assertNotNull($message->delivered_at);
    }

    /** Status still cannot regress, and read is reachable only from a webhook. */
    public function test_status_progresses_only_forward_and_only_from_webhooks(): void
    {
        config(['whatsapp.app_secret' => 'test-secret']);
        $automation = $this->welcome();
        $a = $this->connection('100000000000001', 'PHONE_A', 'TOKEN_A');

        $message = app(SendWhatsAppMessage::class)->dispatch($this->queued($a, $automation, 'iso:rank'));
        // An accepted API call is `sent`, never `delivered`.
        $this->assertSame(WhatsAppMessage::STATUS_SENT, $message->status);

        $this->webhook((string) $message->provider_message_id, 'PHONE_A', 'read')->assertOk();
        $this->assertSame(WhatsAppMessage::STATUS_READ, $message->refresh()->status);

        // A later, lower-ranked event cannot walk it back.
        $this->webhook((string) $message->provider_message_id, 'PHONE_A', 'delivered')->assertOk();
        $this->assertSame(WhatsAppMessage::STATUS_READ, $message->refresh()->status);
    }

    // ── Template rules ──────────────────────────────────────────────────────────────────────────

    /** An unapproved template cannot production-send, whatever the automation says. */
    public function test_an_unapproved_template_cannot_send(): void
    {
        $automation = WhatsAppAutomation::forKey(WhatsAppAutomation::WELCOME);
        $automation->update([
            'enabled' => true, 'template_name' => 'inventra_welcome', 'template_status' => 'PENDING',
        ]);
        $a = $this->connection('100000000000001', 'PHONE_A', 'TOKEN_A');

        $sent = app(SendWhatsAppMessage::class)->dispatch($this->queued($a, $automation->refresh(), 'iso:pending'));

        $this->assertSame(WhatsAppMessage::STATUS_FAILED, $sent->status);
        $this->assertSame('template_unavailable', $sent->failure_code);
        $this->assertCount(0, $this->provider->sent, 'nothing may reach Meta without an approved template');
    }

    /** With no template mapped at all, there is nothing Meta would accept. */
    public function test_an_unmapped_automation_cannot_send(): void
    {
        $automation = WhatsAppAutomation::forKey(WhatsAppAutomation::WELCOME);
        $automation->update(['enabled' => true]);
        $a = $this->connection('100000000000001', 'PHONE_A', 'TOKEN_A');

        $sent = app(SendWhatsAppMessage::class)->dispatch($this->queued($a, $automation->refresh(), 'iso:unmapped'));

        $this->assertSame(WhatsAppMessage::STATUS_FAILED, $sent->status);
        $this->assertCount(0, $this->provider->sent);
    }

    /** The approved template's name, language and ordered parameters are what Meta receives. */
    public function test_the_template_payload_carries_name_language_and_ordered_parameters(): void
    {
        $automation = WhatsAppAutomation::forKey(WhatsAppAutomation::POST_PURCHASE);
        $automation->update([
            'enabled' => true,
            'template_name' => 'inventra_post_purchase',
            'template_language' => 'en_GB',
            'template_status' => 'APPROVED',
            'template_variables' => ['customer_name', 'business_name', 'sale_total'],
        ]);
        $a = $this->connection('100000000000001', 'PHONE_A', 'TOKEN_A');

        $message = $this->queued($a, $automation->refresh(), 'iso:params');
        $message->template_values = [
            'customer_name' => 'Emeka Obi', 'business_name' => 'AutoParts NG',
            'sale_total' => '₦9,200.00', 'balance_due' => '₦0.00',
        ];
        $message->save();

        app(SendWhatsAppMessage::class)->dispatch($message);

        $sent = $this->provider->sent[0];
        $this->assertSame('inventra_post_purchase', $sent['template']);
        $this->assertSame('en_GB', $sent['language']);
        // Ordered exactly as mapped, and only the mapped variables — balance_due is absent.
        $this->assertSame(['Emeka Obi', 'AutoParts NG', '₦9,200.00'], $sent['parameters']);
    }

    /** A variable outside the automation's allowlist can never become a template parameter. */
    public function test_an_unmapped_variable_cannot_reach_the_payload(): void
    {
        $automation = WhatsAppAutomation::forKey(WhatsAppAutomation::WELCOME);
        $automation->update([
            'enabled' => true, 'template_name' => 'inventra_welcome', 'template_status' => 'APPROVED',
            // `sale_total` belongs to post-purchase, not welcome.
            'template_variables' => ['customer_name', 'sale_total', 'business_name'],
        ]);
        $a = $this->connection('100000000000001', 'PHONE_A', 'TOKEN_A');

        app(SendWhatsAppMessage::class)->dispatch($this->queued($a, $automation->refresh(), 'iso:allowlist'));

        $sent = $this->provider->sent[0];
        $this->assertCount(2, $sent['parameters'], 'only the automation\'s own variables may be sent');
    }
}
