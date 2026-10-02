<?php

namespace Tests\Feature\WhatsAppAutomation;

use App\Actions\WhatsAppAutomation\CompleteMetaOnboarding;
use App\Actions\WhatsAppAutomation\StartWhatsAppOnboarding;
use App\Actions\WhatsAppAutomation\WhatsAppAutomationTriggers;
use App\Contracts\WhatsAppConnectionProvider;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\User;
use App\Models\WhatsAppAutomation;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Services\AuditLogger;
use App\Support\WhatsApp\OnboardingFailure;
use App\Support\WhatsApp\WhatsAppTemplate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Fakes\FakeWhatsAppProvider;
use Tests\TestCase;

/**
 * The WhatsApp Automation module.
 *
 * The rules worth protecting: a message is never sent without consent, never sent twice for one
 * event, never claimed delivered without provider evidence, and never configurable by anyone but an
 * Administrator.
 */
class WhatsAppAutomationTest extends TestCase
{
    private ?string $browserSession = null;

    use RefreshDatabase;

    private User $admin;

    private FakeWhatsAppProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame(
            'inventra_test',
            DB::connection()->getDatabaseName(),
            'Refusing to run: these tests write, and this is not the test database.'
        );

        $this->admin = User::factory()->create(['role' => UserRole::Admin, 'phone' => '+2348012345678']);
        $this->provider = new FakeWhatsAppProvider;
        $this->app->instance(WhatsAppConnectionProvider::class, $this->provider);
    }

    /** Runs the browser's side of Embedded Signup: a server-issued state, then Meta's result. */
    private function onboard(array $overrides = []): TestResponse
    {
        // One browser session across both requests, exactly as the page runs it.
        $this->withCredentials()->withCookie(config('session.cookie'), $this->browserSession ??= Str::random(40));
        $state = $this->actingAs($this->admin)->postJson(route('whatsapp.automation.connection.start'))->assertOk()->json('state');

        return $this->actingAs($this->admin)->postJson(route('whatsapp.automation.connection.complete'), array_merge([
            'state' => $state, 'code' => 'meta-exchange-code', 'waba_id' => '100000000000001',
            'phone_number_id' => '200000000000001', 'pin' => '123456',
        ], $overrides));
    }

    /** The action directly, with a state issued to the given session. */
    private function completeDirectly(): WhatsAppConnection
    {
        $state = app(StartWhatsAppOnboarding::class)->execute($this->admin, 'direct-session');

        return app(CompleteMetaOnboarding::class)->execute($this->admin, $state, 'direct-session', 'meta-exchange-code', '100000000000001', '200000000000001', '123456');
    }

    /** Establishes a connection the way Meta would: full identity, real token, approved template. */
    private function connect(): void
    {
        WhatsAppConnection::query()->firstOrCreate(['singleton_key' => 'whatsapp'])->forceFill([
            'provider' => 'fake',
            'waba_id' => '100000000000001',
            'phone_number_id' => '200000000000001',
            'display_phone_number' => '+234 700 000 1234',
            'phone_number' => '+2347000001234',
            'access_token' => 'fake-business-token',
            'status' => 'connected',
            'verified_at' => now(),
            'connected_at' => now(),
        ])->save();
    }

    private function enable(string $key): WhatsAppAutomation
    {
        $automation = WhatsAppAutomation::forKey($key);
        $automation->update([
            'enabled' => true,
            // Business-initiated messages require an approved Meta template, so an enabled
            // automation without one could never send.
            'template_name' => 'inventra_'.$key,
            'template_language' => 'en',
            'template_status' => 'APPROVED',
        ]);

        return $automation->refresh();
    }

    private function consentingCustomer(): Customer
    {
        return Customer::factory()->create([
            'phone' => '+2348090000001',
            'is_active' => true,
            'whatsapp_opt_in' => true,
            'whatsapp_opt_in_at' => now(),
            'whatsapp_opt_out_at' => null,
        ]);
    }

    // ── Authorization ───────────────────────────────────────────────────────────────────────────

    public function test_an_administrator_can_open_the_module(): void
    {
        $this->actingAs($this->admin)->get(route('whatsapp.automation.index'))->assertOk();
    }

    /** Configuration decides what Inventra says to customers, so it is Admin-only server-side. */
    public function test_non_administrators_are_refused_every_configuration_endpoint(): void
    {
        $automation = WhatsAppAutomation::forKey(WhatsAppAutomation::WELCOME);

        foreach ([UserRole::Manager, UserRole::SalesRep] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->actingAs($user)->get(route('whatsapp.automation.index'))->assertForbidden();
            $this->actingAs($user)->postJson(route('whatsapp.automation.toggle', $automation), ['enabled' => true])->assertForbidden();
            $this->actingAs($user)->put(route('whatsapp.automation.update', $automation), ['body' => 'Hi'])->assertForbidden();
            $this->actingAs($user)->getJson(route('whatsapp.automation.connection.config'))->assertForbidden();
            $this->actingAs($user)->postJson(route('whatsapp.automation.connection.complete'), ['code' => 'x'])->assertForbidden();
            $this->actingAs($user)->postJson(route('whatsapp.automation.connection.start'))->assertForbidden();
            $this->actingAs($user)->post(route('whatsapp.automation.connection.disconnect'))->assertForbidden();
            $this->actingAs($user)->post(route('whatsapp.automation.templates.sync'))->assertForbidden();
        }

        $this->assertFalse(WhatsAppAutomation::forKey(WhatsAppAutomation::WELCOME)->enabled);
    }

    // ── Connection / OTP ────────────────────────────────────────────────────────────────────────

    public function test_the_page_starts_disconnected_and_says_so(): void
    {
        $html = $this->actingAs($this->admin)->get(route('whatsapp.automation.index'))->assertOk()->getContent();

        $this->assertStringContainsString("WhatsApp isn't connected yet", $html);
        $this->assertStringContainsString('Connect Business WhatsApp to continue automation', $html);
        $this->assertFalse(WhatsAppConnection::forCurrentBusiness()->isConnected());
    }

    /**
     * Embedded Signup's client-side configuration carries only what Meta requires in the browser.
     *
     * The app secret is what exchanges the code for a business token; if it ever reached the
     * browser, anyone could mint credentials for a connected business.
     */
    public function test_the_connection_config_never_exposes_the_app_secret(): void
    {
        config([
            'whatsapp.app_id' => '1234567890',
            'whatsapp.config_id' => '9876543210',
            'whatsapp.app_secret' => 'SUPER-SECRET-APP-SECRET',
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson(route('whatsapp.automation.connection.config'))
            ->assertOk()
            ->assertJsonPath('app_id', '1234567890')
            ->assertJsonPath('config_id', '9876543210');

        $this->assertStringNotContainsString('SUPER-SECRET-APP-SECRET', $response->getContent());
        $this->assertArrayNotHasKey('app_secret', $response->json());
    }

    /** A completed Embedded Signup records the identity Meta resolved. */
    public function test_completing_onboarding_stores_the_meta_identity(): void
    {
        $this->onboard()
            ->assertOk()
            ->assertJsonPath('status', 'connected');

        $connection = WhatsAppConnection::forCurrentBusiness();
        $this->assertTrue($connection->isConnected());
        $this->assertSame('100000000000001', $connection->waba_id);
        $this->assertSame('200000000000001', $connection->phone_number_id);
        // The number shown comes from Meta, never from anything typed in step 1.
        $this->assertSame('+234 700 000 1234', $connection->display_phone_number);
    }

    /**
     * The token is encrypted at rest and hidden from serialisation.
     *
     * Ciphertext in the column means a database dump cannot send as the business; hiding it from
     * `toArray()` means it cannot leak into a view, a log line, an audit row or a test snapshot.
     */
    public function test_the_access_token_is_encrypted_and_hidden(): void
    {
        $this->onboard()->assertOk();

        $raw = (string) DB::table('whatsapp_connection')->value('access_token');
        $this->assertNotSame('fake-business-token', $raw, 'the token must not be stored in plaintext');
        $this->assertNotEmpty($raw);

        $connection = WhatsAppConnection::forCurrentBusiness();
        // Readable in memory, absent from serialisation.
        $this->assertSame('fake-business-token', $connection->access_token);
        $this->assertArrayNotHasKey('access_token', $connection->toArray());
        $this->assertStringNotContainsString('fake-business-token', $connection->toJson());

        // And absent from every audit row the connection produced.
        foreach (DB::table('audit_logs')->pluck('new_values') as $values) {
            $this->assertStringNotContainsString('fake-business-token', (string) $values);
        }
    }

    /** No token or identifier reaches the rendered page. */
    public function test_no_token_reaches_the_rendered_page(): void
    {
        $this->onboard()->assertOk();

        $html = $this->actingAs($this->admin)->get(route('whatsapp.automation.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('fake-business-token', $html);
        $this->assertStringContainsString('Business number connected', $html);
    }

    /** The connection and its audit evidence commit together. */
    public function test_a_completed_connection_is_audited_once(): void
    {
        $connection = $this->completeDirectly();

        $audit = DB::table('audit_logs')->where('action', 'whatsapp_connected')->sole();
        $this->assertSame($connection->id, (int) $audit->auditable_id);
        $this->assertSame($this->admin->id, (int) $audit->actor_id);
        $this->assertSame(['100000000000001'], $this->provider->subscribed);
    }

    /** Without its audit evidence the connection must not commit. */
    public function test_a_failing_audit_write_rolls_back_the_connection(): void
    {
        $before = DB::table('whatsapp_connection')->orderBy('id')->get()->toArray();
        $this->app->bind(AuditLogger::class, fn () => new class extends AuditLogger
        {
            public function record(string $action, Model $auditable, ?User $actor,
                array $oldValues = [], array $newValues = [], array $metadata = [],
                bool $explicitDiff = false): void
            {
                throw new RuntimeException('audit unavailable');
            }
        });

        try {
            $this->completeDirectly();
            $this->fail('The connection must not commit without its audit evidence.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertEquals($before, DB::table('whatsapp_connection')->orderBy('id')->get()->toArray());
        $this->assertFalse(DB::table('whatsapp_connection')->where('waba_id', '100000000000001')->exists());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'whatsapp_connected')->count());
        // Subscription and registration precede the local write, and only after verification: a
        // failed write leaves the Business disconnected here, never connected on Meta's say-so.
        $this->assertFalse(WhatsAppConnection::forCurrentBusiness()->isConnected());
    }

    /** Meta refusing means no connection — never an optimistic one. */
    public function test_a_provider_refusal_prevents_connection(): void
    {
        $this->provider->onboardingFailure = OnboardingFailure::PhoneNotInWaba;

        $this->onboard()->assertStatus(422)->assertJsonPath('errors.connection.0', OnboardingFailure::PhoneNotInWaba->message());

        $this->assertFalse(WhatsAppConnection::forCurrentBusiness()->isConnected());
    }

    /** Without application credentials there is no honest connection to start. */
    public function test_an_unconfigured_provider_refuses_rather_than_pretending(): void
    {
        $this->provider->configured = false;

        $this->actingAs($this->admin)->postJson(route('whatsapp.automation.connection.start'))->assertStatus(422);
        $this->actingAs($this->admin)->postJson(route('whatsapp.automation.connection.complete'), [
            'state' => str_repeat('a', 64), 'code' => 'meta-exchange-code', 'waba_id' => '1', 'phone_number_id' => '2', 'pin' => '123456',
        ])->assertStatus(422);

        $this->assertFalse(WhatsAppConnection::forCurrentBusiness()->isConnected());
    }

    /** A missing code cannot produce a connection. */
    public function test_a_missing_code_is_refused(): void
    {
        $this->onboard(['code' => ''])->assertStatus(422)->assertJsonValidationErrors('code');

        $this->assertFalse(WhatsAppConnection::forCurrentBusiness()->isConnected());
    }

    /**
     * The database itself refuses a connection without a real Meta identity.
     *
     * This is the structural guarantee behind the audit finding: no code path, present or future,
     * can mark a row connected that could not actually send.
     */
    public function test_the_database_refuses_a_connection_without_meta_identity(): void
    {
        $this->expectException(QueryException::class);

        DB::table('whatsapp_connection')->where('singleton_key', 'whatsapp')->update([
            'status' => 'connected',
            'verified_at' => now(),
        ]);
    }

    // ── Toggles and templates ───────────────────────────────────────────────────────────────────

    public function test_a_toggle_is_persisted(): void
    {
        $automation = WhatsAppAutomation::forKey(WhatsAppAutomation::WELCOME);

        $this->actingAs($this->admin)
            ->postJson(route('whatsapp.automation.toggle', $automation), ['enabled' => true])
            ->assertOk();

        $this->assertTrue($automation->refresh()->enabled);
        $this->assertDatabaseHas('audit_logs', ['action' => 'whatsapp_automation_enabled']);
    }

    /** A token this automation does not offer is refused server-side. */
    public function test_an_unknown_variable_is_rejected(): void
    {
        $automation = WhatsAppAutomation::forKey(WhatsAppAutomation::WELCOME);
        $original = $automation->body;

        $this->actingAs($this->admin)
            ->putJson(route('whatsapp.automation.update', $automation), ['body' => 'Hi {{sale_total}}'])
            ->assertStatus(422);

        $this->assertSame($original, $automation->refresh()->body);
    }

    /** Nothing in a saved template is ever evaluated. */
    public function test_a_template_body_is_never_executed(): void
    {
        $automation = WhatsAppAutomation::forKey(WhatsAppAutomation::WELCOME);
        $hostile = 'Hi {{customer_name}} {{ 7 * 7 }} <script>alert(1)</script> {{ $x }}';

        // Braces that are not allowlisted tokens are refused outright.
        $this->actingAs($this->admin)
            ->putJson(route('whatsapp.automation.update', $automation), ['body' => $hostile])
            ->assertStatus(422);

        // And a body containing markup renders as literal characters, never as evaluated output.
        $rendered = WhatsAppTemplate::render(
            'welcome',
            'Hi {{customer_name}} <b>x</b>',
            ['customer_name' => '<script>alert(1)</script>'],
        );
        $this->assertSame('Hi <script>alert(1)</script> <b>x</b>', $rendered);
        $this->assertStringNotContainsString('49', $rendered);
    }

    public function test_a_valid_template_saves(): void
    {
        $automation = WhatsAppAutomation::forKey(WhatsAppAutomation::WELCOME);

        $this->actingAs($this->admin)
            ->putJson(route('whatsapp.automation.update', $automation), [
                'body' => 'Hi {{customer_name}}, welcome to {{business_name}}.',
                'enabled' => true,
            ])
            ->assertOk();

        $this->assertSame('Hi {{customer_name}}, welcome to {{business_name}}.', $automation->refresh()->body);
        $this->assertTrue($automation->enabled);
    }

    // ── Welcome ─────────────────────────────────────────────────────────────────────────────────

    public function test_a_new_consenting_customer_is_welcomed_once(): void
    {
        $this->connect();
        $this->enable(WhatsAppAutomation::WELCOME);

        $customer = $this->consentingCustomer();
        app(WhatsAppAutomationTriggers::class)->customerCreated($customer);

        $this->assertSame(1, WhatsAppMessage::query()->where('type', 'welcome')->count());

        // Re-running the same event — a retried request, a repeated save — adds nothing.
        app(WhatsAppAutomationTriggers::class)->customerCreated($customer);
        $this->assertSame(1, WhatsAppMessage::query()->where('type', 'welcome')->count());
    }

    public function test_a_customer_without_consent_is_never_messaged(): void
    {
        $this->connect();
        $this->enable(WhatsAppAutomation::WELCOME);

        $customer = Customer::factory()->create([
            'phone' => '+2348090000002',
            'is_active' => true,
            'whatsapp_opt_in' => false,
            'whatsapp_opt_in_at' => null,
        ]);

        app(WhatsAppAutomationTriggers::class)->customerCreated($customer);

        $this->assertSame(0, WhatsAppMessage::query()->count());
    }

    /** A disabled automation produces nothing: the switch is not cosmetic. */
    public function test_a_disabled_automation_sends_nothing(): void
    {
        $this->connect();
        $customer = $this->consentingCustomer();

        app(WhatsAppAutomationTriggers::class)->customerCreated($customer);

        $this->assertSame(0, WhatsAppMessage::query()->count());
    }

    /** Without a connected number nothing is queued, whatever the switches say. */
    public function test_nothing_is_queued_while_disconnected(): void
    {
        $this->enable(WhatsAppAutomation::WELCOME);
        $customer = $this->consentingCustomer();

        app(WhatsAppAutomationTriggers::class)->customerCreated($customer);

        $this->assertSame(0, WhatsAppMessage::query()->count());
    }
}
