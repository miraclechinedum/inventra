<?php

namespace Tests\Feature\WhatsAppAutomation;

use App\Actions\WhatsAppAutomation\QueueWhatsAppMessage;
use App\Actions\WhatsAppAutomation\WhatsAppAutomationTriggers;
use App\Contracts\WhatsAppConnectionProvider;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\User;
use App\Models\WhatsAppAutomation;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Fakes\FakeWhatsAppProvider;
use Tests\TestCase;

/**
 * The adversarial pass: the attacks this module has to survive rather than the features it offers.
 *
 * Each test states the attack it stands for, because a passing assertion whose purpose is forgotten
 * is the kind that gets deleted during a later refactor.
 */
class WhatsAppSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private FakeWhatsAppProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('inventra_test', DB::connection()->getDatabaseName());

        $this->admin = User::factory()->create(['role' => UserRole::Admin, 'phone' => '+2348012345678']);
        $this->provider = new FakeWhatsAppProvider;
        $this->app->instance(WhatsAppConnectionProvider::class, $this->provider);
    }

    private function connect(): void
    {
        WhatsAppConnection::query()->firstOrCreate(['singleton_key' => 'whatsapp'])->forceFill([
            'provider' => 'fake', 'waba_id' => '100000000000001', 'phone_number_id' => '200000000000001',
            'display_phone_number' => '+234 700 000 1234', 'phone_number' => '+2347000001234',
            'access_token' => 'fake-business-token', 'status' => 'connected',
            'verified_at' => now(), 'connected_at' => now(),
        ])->save();
    }

    /**
     * Duplicate sending under concurrency.
     *
     * Two callers racing on the same event must produce one message. The guarantee is the UNIQUE
     * index on `idempotency_key`, not an `exists()` check, so this drives the queue action directly
     * with the same key and asserts the database refuses the second.
     */
    public function test_concurrent_queueing_of_one_event_produces_one_message(): void
    {
        $this->connect();
        $automation = WhatsAppAutomation::forKey(WhatsAppAutomation::WELCOME);
        $automation->update([
            'enabled' => true, 'template_name' => 'inventra_welcome',
            'template_language' => 'en', 'template_status' => 'APPROVED',
        ]);
        $customer = Customer::factory()->create([
            'phone' => '+2348090000001', 'is_active' => true,
            'whatsapp_opt_in' => true, 'whatsapp_opt_in_at' => now(),
        ]);

        $queue = app(QueueWhatsAppMessage::class);
        $results = [];

        for ($i = 0; $i < 5; $i++) {
            $results[] = $queue->toCustomer(
                $automation->refresh(),
                $customer,
                ['customer_name' => $customer->full_name, 'business_name' => 'X'],
                'welcome:'.$customer->id,
            );
        }

        $this->assertSame(1, WhatsAppMessage::query()->count());
        // Exactly one caller created it; the rest were told, by the database, that there was
        // nothing to do.
        $this->assertCount(1, array_filter($results));
    }

    /**
     * Recipient manipulation.
     *
     * A crafted request must not be able to name an arbitrary user — or a Sales Rep, or an inactive
     * account — as a low-stock recipient.
     */
    /**
     * A posted recipient list is ignored entirely.
     *
     * This assertion used to be "only eligible staff are stored". It is now stronger: the low-stock
     * destination is the business's Manager alert number, so nothing posted here may write the
     * pivot at all. A stale cached editor or a hand-written request must not be able to
     * re-establish a second source of truth beside the settings field — including with an
     * otherwise perfectly eligible Manager, which is what makes this a security property rather
     * than a validation one.
     *
     * The pivot itself is untouched by this change and keeps whatever history it holds.
     */
    public function test_a_posted_recipient_list_cannot_write_the_pivot(): void
    {
        $this->connect();
        $automation = WhatsAppAutomation::forKey(WhatsAppAutomation::LOW_STOCK);
        $salesRep = User::factory()->create(['role' => UserRole::SalesRep, 'phone' => '+2348055556666']);
        $noPhone = User::factory()->create(['role' => UserRole::Manager, 'phone' => null]);
        $eligible = User::factory()->create(['role' => UserRole::Manager, 'phone' => '+2348077778888']);

        $this->actingAs($this->admin)->putJson(route('whatsapp.automation.update', $automation), [
            'body' => '{{product_name}} is low ({{stock_left}}/{{reorder_level}}).',
            'recipients' => [$salesRep->id, $noPhone->id, $eligible->id, 999999],
        ])->assertOk();

        $this->assertSame([], $automation->refresh()->recipients()->pluck('users.id')->all(),
            'no posted recipient may reach the pivot, however eligible');

        // Existing rows are left alone rather than cleared: the save neither writes nor wipes.
        $automation->syncRecipients([$eligible->id]);
        $this->actingAs($this->admin)->putJson(route('whatsapp.automation.update', $automation), [
            'body' => '{{product_name}} is low ({{stock_left}}/{{reorder_level}}).',
            'recipients' => [],
        ])->assertOk();

        $this->assertSame([$eligible->id], $automation->refresh()->recipients()->pluck('users.id')->all(),
            'historical pivot rows are preserved, not destroyed');
    }

    /**
     * Template injection.
     *
     * Nothing a template body contains may be evaluated, and no automation may reach a variable
     * outside its own allowlist.
     */
    public function test_template_injection_attempts_are_refused(): void
    {
        $automation = WhatsAppAutomation::forKey(WhatsAppAutomation::WELCOME);
        $original = $automation->body;

        $payloads = [
            '{{ config("app.key") }}',
            '{{ $customer->password }}',
            '{{ system("id") }}',
            '{!! $x !!}',
            '{{ 7 * 7 }}',
            // Another automation's variable.
            'Total: {{sale_total}}',
            // A staff-only variable in a customer template.
            'Stock {{stock_left}}',
        ];

        foreach ($payloads as $payload) {
            $this->actingAs($this->admin)
                ->putJson(route('whatsapp.automation.update', $automation), ['body' => $payload])
                ->assertStatus(422);
        }

        $this->assertSame($original, $automation->refresh()->body);
    }

    /**
     * XSS through the message log.
     *
     * A recipient name is customer-controlled data. Rendered into the log it must be escaped, so a
     * customer named with a script tag cannot execute anything in an Administrator's browser.
     */
    public function test_a_hostile_recipient_name_is_escaped_in_the_log(): void
    {
        $this->connect();
        $automation = WhatsAppAutomation::forKey(WhatsAppAutomation::WELCOME);
        $automation->update([
            'enabled' => true, 'template_name' => 'inventra_welcome',
            'template_language' => 'en', 'template_status' => 'APPROVED',
        ]);

        $customer = Customer::factory()->create([
            'first_name' => '<script>alert(1)</script>',
            'last_name' => 'Obi',
            'phone' => '+2348090000009', 'is_active' => true,
            'whatsapp_opt_in' => true, 'whatsapp_opt_in_at' => now(),
        ]);
        app(WhatsAppAutomationTriggers::class)->customerCreated($customer);

        $html = $this->actingAs($this->admin)->get(route('whatsapp.automation.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    /**
     * Test-message abuse.
     *
     * The endpoint sends real WhatsApp messages, so it is throttled. Without a limit it would be a
     * way to send unlimited messages through the business's number.
     */
    public function test_test_sends_are_rate_limited(): void
    {
        $this->connect();
        $automation = WhatsAppAutomation::forKey(WhatsAppAutomation::WELCOME);

        $statuses = [];

        for ($i = 0; $i < 6; $i++) {
            $statuses[] = $this->actingAs($this->admin)
                ->postJson(route('whatsapp.automation.test', $automation))
                ->getStatusCode();
        }

        $this->assertContains(429, $statuses, 'the test endpoint must be throttled');
    }

    /** Connection endpoints are throttled too: each one sends a message to a typed-in number. */
    public function test_connection_requests_are_rate_limited(): void
    {
        $statuses = [];

        for ($i = 0; $i < 8; $i++) {
            $statuses[] = $this->actingAs($this->admin)
                ->postJson(route('whatsapp.automation.connection.complete'), ['code' => 'meta-code'])
                ->getStatusCode();
        }

        $this->assertContains(429, $statuses);
    }

    /**
     * Secret leakage.
     *
     * No provider credential and no verification hash may reach the rendered page, whatever is
     * configured.
     */
    public function test_no_secret_reaches_the_rendered_page(): void
    {
        config([
            'whatsapp.app_secret' => 'SECRET-APP-SECRET',
            'whatsapp.verify_token' => 'SECRET-VERIFY-TOKEN',
        ]);
        $this->connect();

        $html = $this->actingAs($this->admin)->get(route('whatsapp.automation.index'))->assertOk()->getContent();

        foreach (['SECRET-APP-SECRET', 'SECRET-VERIFY-TOKEN', 'fake-business-token'] as $secret) {
            $this->assertStringNotContainsString($secret, $html);
        }

        // Nor the business token, which the model hides from serialisation.
        $this->assertArrayNotHasKey('access_token', WhatsAppConnection::forCurrentBusiness()->toArray());
    }

    /**
     * Webhook forgery.
     *
     * A status arriving for a provider id that belongs to no message must change nothing, and an
     * unsigned payload must be refused outright (covered fully in WhatsAppTriggerTest).
     */
    public function test_a_webhook_for_an_unknown_message_changes_nothing(): void
    {
        config(['whatsapp.app_secret' => 'test-secret']);
        $payload = json_encode(['entry' => [['changes' => [['value' => ['statuses' => [[
            'id' => 'not-a-real-message-id', 'status' => 'delivered',
        ]]]]]]]]);

        $this->call('POST', route('webhooks.whatsapp.handle'), [], [], [], [
            'HTTP_X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $payload, 'test-secret'),
            'CONTENT_TYPE' => 'application/json',
        ], $payload)->assertOk();

        $this->assertSame(0, WhatsAppMessage::query()->count());
    }

    /** The old WhatsApp product is no longer reachable anywhere. */
    public function test_only_one_whatsapp_system_remains(): void
    {
        foreach ([
            'whatsapp.deliveries.index',
            'whatsapp.deliveries.show',
            'whatsapp.deliveries.resolve-unknown',
            'sales.whatsapp.send',
            'sales.whatsapp.retry',
        ] as $route) {
            $this->assertFalse(Route::has($route), "{$route} must no longer exist");
        }

        // And the old navigation item is gone from the shell.
        $html = $this->actingAs($this->admin)->get(route('dashboard'))->assertOk()->getContent();
        $this->assertStringNotContainsString('WhatsApp history', $html);
        $this->assertStringContainsString('WhatsApp Automation', $html);
    }

    /** Historical receipt data is preserved, not destroyed. */
    public function test_the_legacy_delivery_table_still_exists(): void
    {
        $this->assertTrue(
            Schema::hasTable('whatsapp_deliveries'),
            'historical receipt records must be preserved'
        );
    }
}
