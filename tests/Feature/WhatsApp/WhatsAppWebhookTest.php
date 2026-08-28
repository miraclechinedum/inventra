<?php

namespace Tests\Feature\WhatsApp;

use App\Actions\WhatsApp\SendWhatsAppReceipt;
use App\Contracts\WhatsAppClient;
use App\Enums\UserRole;
use App\Enums\WhatsAppDeliveryStatus;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\User;
use App\Models\WhatsAppDelivery;
use App\Support\WhatsAppSendResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use LogicException;
use Tests\Fakes\FakeWhatsAppClient;
use Tests\TestCase;

class WhatsAppWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const APP_SECRET = 'non-production-test-secret';

    private FakeWhatsAppClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('whatsapp.verify_token', 'non-production-verify-token');
        config()->set('whatsapp.app_secret', self::APP_SECRET);
        $this->client = new FakeWhatsAppClient;
        $this->app->instance(WhatsAppClient::class, $this->client);
    }

    public function test_get_verification_requires_valid_timing_safe_token(): void
    {
        $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=non-production-verify-token&hub.challenge=challenge-123')
            ->assertOk()->assertSeeText('challenge-123');
        $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=challenge-123')->assertForbidden();
        $this->assertStringNotContainsString('non-production-verify-token', $this->get('/webhooks/whatsapp')->getContent());
    }

    public function test_post_requires_signature_valid_json_and_size_limit(): void
    {
        $this->call('POST', '/webhooks/whatsapp', server: ['CONTENT_TYPE' => 'application/json'], content: '{}')->assertForbidden();
        $this->signedPost('{bad json')->assertStatus(422);
        $this->call('POST', '/webhooks/whatsapp', server: [
            'CONTENT_LENGTH' => '262145',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256=invalid',
        ], content: '{}')
            ->assertStatus(413);
    }

    public function test_signature_variants_authenticate_the_exact_raw_body_before_processing(): void
    {
        $raw = '{}';
        $valid = 'sha256='.hash_hmac('sha256', $raw, self::APP_SECRET);
        $this->call('POST', '/webhooks/whatsapp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $valid,
        ], content: $raw)->assertOk();

        foreach ([null, 'sha256=bad', 'sha1='.hash_hmac('sha1', $raw, self::APP_SECRET), 'malformed-header'] as $signature) {
            $server = ['CONTENT_TYPE' => 'application/json'];

            if ($signature !== null) {
                $server['HTTP_X_HUB_SIGNATURE_256'] = $signature;
            }

            $this->call('POST', '/webhooks/whatsapp', server: $server, content: $raw)->assertForbidden();
        }

        $this->call('POST', '/webhooks/whatsapp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $valid,
        ], content: '{"modified":true}')->assertForbidden();
    }

    public function test_webhooks_progress_monotonically_and_are_idempotent(): void
    {
        $delivery = $this->acceptedDelivery();

        $this->signedStatus($delivery->provider_message_id, 'sent', 1700000000)->assertOk();
        $this->assertSame(WhatsAppDeliveryStatus::Sent, $delivery->fresh()->status);
        $this->signedStatus($delivery->provider_message_id, 'delivered', 1700000010)->assertOk();
        $this->signedStatus($delivery->provider_message_id, 'delivered', 1700000010)->assertOk();
        $this->assertSame(WhatsAppDeliveryStatus::Delivered, $delivery->fresh()->status);
        $this->signedStatus($delivery->provider_message_id, 'sent', 1700000005)->assertOk();
        $this->assertSame(WhatsAppDeliveryStatus::Delivered, $delivery->fresh()->status);
        $this->signedStatus($delivery->provider_message_id, 'read', 1700000020)->assertOk();
        $this->assertSame(WhatsAppDeliveryStatus::Read, $delivery->fresh()->status);
        $this->signedStatus($delivery->provider_message_id, 'delivered', 1700000025)->assertOk();
        $this->assertSame(WhatsAppDeliveryStatus::Read, $delivery->fresh()->status);
        $this->signedStatus($delivery->provider_message_id, 'sent', 1700000030)->assertOk();
        $this->assertSame(WhatsAppDeliveryStatus::Read, $delivery->fresh()->status);

        $this->signedStatus('unknown-provider-id', 'sent', 1700000000)->assertOk();
        $this->assertDatabaseCount('whatsapp_deliveries', 1);
    }

    public function test_failed_webhook_persists_only_bounded_safe_fields(): void
    {
        $delivery = $this->acceptedDelivery();
        $payload = $this->payload($delivery->provider_message_id, 'failed', 1700000000, [[
            'code' => str_repeat('1', 100),
            'title' => str_repeat('Failure ', 100),
            'unrelated_secret' => 'must-not-persist',
        ]]);

        $this->signedPost($payload)->assertOk();
        $delivery->refresh();
        $this->assertSame(WhatsAppDeliveryStatus::Failed, $delivery->status);
        $this->assertSame(64, mb_strlen($delivery->failure_code));
        $this->assertSame(500, mb_strlen($delivery->failure_reason));
        $this->assertStringNotContainsString('must-not-persist', json_encode($delivery->getAttributes(), JSON_THROW_ON_ERROR));
        $this->signedStatus($delivery->provider_message_id, 'sent', 1700000010)->assertOk();
        $this->assertSame(WhatsAppDeliveryStatus::Failed, $delivery->fresh()->status);
    }

    public function test_invalid_provider_timestamps_fall_back_safely(): void
    {
        foreach (['0', '-1', ['nested'], 'not-a-number', '999999999999999999999999', (string) now()->addYears(10)->timestamp] as $timestamp) {
            $delivery = $this->acceptedDelivery();
            $before = now()->subSecond();
            $this->signedStatus($delivery->provider_message_id, 'sent', $timestamp)->assertOk();

            $delivery->refresh();
            $this->assertTrue($delivery->sent_at->betweenIncluded($before, now()->addSecond()));
            $this->assertSame(WhatsAppDeliveryStatus::Sent, $delivery->status);
        }

        $missing = $this->acceptedDelivery();
        $before = now()->subSecond();
        $this->signedPost($this->payloadWithoutTimestamp($missing->provider_message_id, 'sent'))->assertOk();
        $missing->refresh();
        $this->assertTrue($missing->sent_at->betweenIncluded($before, now()->addSecond()));
        $this->assertSame(WhatsAppDeliveryStatus::Sent, $missing->status);

        $normal = $this->acceptedDelivery();
        $timestamp = now()->subMinute()->startOfSecond();
        $this->signedStatus($normal->provider_message_id, 'sent', (string) $timestamp->timestamp)->assertOk();
        $normal->refresh();
        $this->assertTrue($normal->sent_at->equalTo($timestamp));
        $this->assertSame(WhatsAppDeliveryStatus::Sent, $normal->status);
    }

    public function test_unknown_and_inbound_payloads_are_acknowledged_without_persistence(): void
    {
        $unknown = $this->payload('unknown-provider-id', 'sent', '1700000000');
        $this->signedPost($unknown)->assertOk()->assertSeeText('EVENT_RECEIVED');
        $inbound = json_encode(['entry' => [['changes' => [['value' => [
            'contacts' => [['profile' => ['name' => 'Do not persist'], 'wa_id' => '2348012345678']],
            'messages' => [['from' => '2348012345678', 'text' => ['body' => 'private inbound content']]],
        ]]]]]], JSON_THROW_ON_ERROR);
        $this->signedPost($inbound)->assertOk();

        $this->assertDatabaseCount('whatsapp_deliveries', 0);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'private inbound content']);
    }

    public function test_webhook_has_exact_security_middleware_and_csrf_exemption(): void
    {
        $route = app('router')->getRoutes()->getByName('webhooks.whatsapp.handle');
        $middleware = $route->gatherMiddleware();
        $bootstrap = file_get_contents(base_path('bootstrap/app.php'));

        $this->assertContains('whatsapp.webhook.size', $middleware);
        $this->assertContains('throttle:whatsapp-webhook', $middleware);
        $this->assertStringContainsString("validateCsrfTokens(except: ['webhooks/whatsapp'])", $bootstrap);
        $this->assertStringNotContainsString('webhooks/*', $bootstrap);
    }

    public function test_delivery_records_reject_arbitrary_updates_and_deletes(): void
    {
        $delivery = $this->acceptedDelivery();

        foreach ([
            fn () => tap($delivery, fn ($record) => $record->destination_phone = '+2348099999999')->save(),
            fn () => $delivery->delete(),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Delivery mutation should be controlled.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function acceptedDelivery(): WhatsAppDelivery
    {
        $this->client->result = WhatsAppSendResult::accepted('wamid.'.Str::uuid());
        $seller = User::factory()->create(['role' => UserRole::SalesRep]);
        $customer = Customer::factory()->create([
            'whatsapp_opt_in' => true,
            'whatsapp_opt_in_at' => now()->subMinute(),
            'whatsapp_opt_out_at' => null,
        ]);
        $sale = Sale::factory()->create(['customer_id' => $customer, 'sold_by' => $seller]);

        return app(SendWhatsAppReceipt::class)->execute($seller, $sale, (string) Str::uuid());
    }

    private function signedStatus(string $providerId, string $status, mixed $timestamp)
    {
        return $this->signedPost($this->payload($providerId, $status, $timestamp));
    }

    private function payload(string $providerId, string $status, mixed $timestamp, array $errors = []): string
    {
        return json_encode(['entry' => [['changes' => [['value' => ['statuses' => [[
            'id' => $providerId,
            'status' => $status,
            'timestamp' => $timestamp,
            'errors' => $errors,
        ]]]]]]]], JSON_THROW_ON_ERROR);
    }

    private function payloadWithoutTimestamp(string $providerId, string $status): string
    {
        return json_encode(['entry' => [['changes' => [['value' => ['statuses' => [[
            'id' => $providerId,
            'status' => $status,
        ]]]]]]]], JSON_THROW_ON_ERROR);
    }

    private function signedPost(string $raw)
    {
        $signature = 'sha256='.hash_hmac('sha256', $raw, self::APP_SECRET);

        return $this->call('POST', '/webhooks/whatsapp', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
        ], content: $raw);
    }
}
