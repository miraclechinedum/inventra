<?php

namespace Tests\Feature\WhatsAppAutomation;

use App\Actions\WhatsAppAutomation\EnsureDefaultAutomations;
use App\Actions\WhatsAppAutomation\QueueWhatsAppMessage;
use App\Actions\WhatsAppAutomation\SyncWhatsAppTemplates;
use App\Contracts\WhatsAppConnectionProvider;
use App\Enums\UserRole;
use App\Models\Business;
use App\Models\User;
use App\Models\WhatsAppAutomation;
use App\Models\WhatsAppConnection;
use App\Services\MetaWhatsAppProvider;
use App\Support\WhatsApp\GraphVersion;
use App\Support\WhatsApp\OnboardingFailure;
use App\Tenancy\CurrentBusiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Concerns\BuildsWhatsAppWorld;
use Tests\Fakes\FakeWhatsAppProvider;
use Tests\TestCase;

/**
 * Embedded Signup end to end: the server-issued state, server-side verification of everything the
 * browser reports, subscription and registration, the connected write, disconnect, default
 * automations and template synchronisation. Two Businesses throughout; nothing reaches Meta.
 */
class EmbeddedSignupTest extends TestCase
{
    use BuildsWhatsAppWorld, RefreshDatabase;

    private const WABA = '100000000000001';

    private const PHONE = '200000000000001';

    private const PIN = '482913';

    private FakeWhatsAppProvider $provider;

    private Business $a;

    private Business $b;

    private User $adminA;

    private User $adminB;

    /** @var array<string, string> operator id => browser session id */
    private array $sessions = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['whatsapp.app_id' => '555000111', 'whatsapp.app_secret' => 'app-secret-never-shown', 'whatsapp.config_id' => 'cfg-1',
            'whatsapp.graph_version' => 'v26.0', 'whatsapp.verify_token' => 'verify-token-never-shown']);
        $this->provider = new FakeWhatsAppProvider;
        $this->app->instance(WhatsAppConnectionProvider::class, $this->provider);

        $this->a = Business::query()->orderBy('id')->firstOrFail();
        $this->b = Business::factory()->create(['name' => 'Bravo Hardware']);
        $this->adminA = User::factory()->forBusiness($this->a)->create(['role' => UserRole::Admin]);
        $this->adminB = User::factory()->forBusiness($this->b)->create(['role' => UserRole::Admin]);
    }

    /* ------------------------------------------------------------ onboarding state */

    public function test_a_state_is_issued_to_one_business_and_operator_and_stored_only_as_a_hash(): void
    {
        $state = $this->start($this->adminA)->assertOk()->assertJsonMissingPath('app_secret')->json('state');

        $attempt = DB::table('whatsapp_onboarding_attempts')->sole();
        $this->assertSame([$this->a->id, $this->adminA->id, hash('sha256', $state)], [$attempt->business_id, $attempt->user_id, $attempt->state_hash]);
        $this->assertSame(0, DB::table('whatsapp_onboarding_attempts')->where('state_hash', $state)->count(), 'the state itself is never stored');
        $this->assertNull($attempt->consumed_at);
    }

    public function test_a_state_cannot_be_used_by_another_business_operator_or_session(): void
    {
        $state = $this->start($this->adminA)->json('state');
        $colleague = User::factory()->forBusiness($this->a)->create(['role' => UserRole::Admin]);

        $this->complete($this->adminB, $state)->assertUnprocessable()->assertJsonPath('errors.connection.0', OnboardingFailure::StateInvalid->message());
        $this->complete($colleague, $state)->assertUnprocessable()->assertJsonPath('errors.connection.0', OnboardingFailure::StateInvalid->message());
        $this->complete($this->adminA, $state, session: 'another-browser-session-aaaaaaaaaaaaaaaaaa')->assertUnprocessable();

        $this->assertNull(DB::table('whatsapp_onboarding_attempts')->value('consumed_at'), 'a stranger cannot even spend it');
        $this->assertSame([], $this->provider->exchanged, 'no code is exchanged for a state that is not the operator\'s');

        // Its owner still can.
        $this->complete($this->adminA, $state)->assertOk();
    }

    public function test_an_expired_or_spent_state_is_refused(): void
    {
        $state = $this->start($this->adminA)->json('state');
        DB::table('whatsapp_onboarding_attempts')->update(['expires_at' => now()->subSecond()]);
        $this->complete($this->adminA, $state)->assertUnprocessable()->assertJsonPath('errors.connection.0', OnboardingFailure::StateInvalid->message());

        $state = $this->start($this->adminA)->json('state');
        $this->provider->onboardingFailure = OnboardingFailure::ExchangeFailed;
        $this->complete($this->adminA, $state)->assertUnprocessable();
        $this->provider->onboardingFailure = null;

        // Spent on first use, whatever the outcome: a replay is refused before Meta is asked again.
        $exchanges = count($this->provider->exchanged);
        $this->complete($this->adminA, $state)->assertUnprocessable()->assertJsonPath('errors.connection.0', OnboardingFailure::StateInvalid->message());
        $this->assertCount($exchanges, $this->provider->exchanged);
        $this->assertFalse(WhatsAppConnection::forBusiness($this->a)->isConnected());
    }

    public function test_a_submitted_business_is_refused_and_the_operators_own_is_used(): void
    {
        $state = $this->start($this->adminA)->json('state');

        $this->complete($this->adminA, $state, ['business_id' => $this->b->id])->assertUnprocessable()->assertJsonValidationErrors('business_id');
        $this->assertFalse(WhatsAppConnection::forBusiness($this->b)->exists);
    }

    /* ----------------------------------------------------------- flow and failures */

    public function test_connected_is_reached_only_after_verification_subscription_and_registration(): void
    {
        $this->complete($this->adminA, $this->start($this->adminA)->json('state'))->assertOk()->assertJsonPath('status', 'connected');

        $connection = WhatsAppConnection::forBusiness($this->a);
        $this->assertSame([true, self::WABA, self::PHONE, $this->adminA->id], [$connection->isConnected(), $connection->waba_id, $connection->phone_number_id, $connection->connected_by]);
        $this->assertSame([self::WABA], $this->provider->subscribed);
        $this->assertSame([self::PHONE], array_column($this->provider->registered, 'phone_number_id'));
        $this->assertSame($connection->id, (int) DB::table('whatsapp_onboarding_attempts')->value('whatsapp_connection_id'));
        $this->assertSame($this->a->id, DB::table('audit_logs')->where('action', 'whatsapp_connected')->value('business_id'));
        $this->assertPinNeverPersisted();
    }

    public function test_every_failed_step_leaves_the_business_disconnected_with_a_classified_reason(): void
    {
        // Seven attempts in a row: the per-minute connection throttle is not what is under test.
        $this->withoutMiddleware(ThrottleRequests::class);

        foreach ([
            [OnboardingFailure::ExchangeFailed, fn () => $this->provider->onboardingFailure = OnboardingFailure::ExchangeFailed],
            [OnboardingFailure::MissingPermission, fn () => $this->provider->onboardingFailure = OnboardingFailure::MissingPermission],
            [OnboardingFailure::WabaInaccessible, fn () => $this->provider->accessible = []],
            [OnboardingFailure::PhoneNotInWaba, fn () => $this->provider->accessible = [self::WABA => ['200000000000099']]],
            [OnboardingFailure::SubscriptionFailed, fn () => $this->provider->subscriptionFails = true],
            [OnboardingFailure::RegistrationRateLimited, fn () => $this->provider->registrationFailure = OnboardingFailure::RegistrationRateLimited],
            [OnboardingFailure::PinRejected, fn () => $this->provider->registrationFailure = OnboardingFailure::PinRejected],
        ] as [$failure, $arrange]) {
            $this->provider = new FakeWhatsAppProvider;
            $this->app->instance(WhatsAppConnectionProvider::class, $this->provider);
            $arrange();

            $this->complete($this->adminA, $this->start($this->adminA)->json('state'))
                ->assertUnprocessable()->assertJsonPath('errors.connection.0', $failure->message());

            $this->assertFalse(WhatsAppConnection::forBusiness($this->a)->isConnected(), $failure->value);
            $this->assertSame($failure->value, DB::table('whatsapp_onboarding_attempts')->latest('id')->value('failure_code'));
        }

        // Nothing was ever subscribed or registered after a verification failure.
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'whatsapp_connected')->count());
        $this->assertPinNeverPersisted();
    }

    /* --------------------------------------------- the real provider, against fake Meta */

    public function test_the_provider_exchanges_and_validates_server_side_using_the_configured_version(): void
    {
        $this->fakeMeta();

        $result = app(MetaWhatsAppProvider::class)->verifyOnboarding('code-xyz', self::WABA, self::PHONE);

        $this->assertTrue($result->confirmed);
        $this->assertSame(['+234 700 000 1234', 'Alpha Spares'], [$result->displayPhoneNumber, $result->verifiedName]);
        Http::assertSent(fn (HttpRequest $request) => str_starts_with($request->url(), 'https://graph.facebook.com/v26.0/oauth/access_token')
            && $request['client_id'] === '555000111' && $request['client_secret'] === 'app-secret-never-shown' && $request['code'] === 'code-xyz');
        Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), '/v26.0/debug_token')
            && $request['input_token'] === 'business-token-1' && $request['access_token'] === '555000111|app-secret-never-shown');
        Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), '/v26.0/'.self::WABA.'/phone_numbers')
            && $request->hasHeader('Authorization', 'Bearer business-token-1'));
        Http::assertNotSent(fn (HttpRequest $request) => str_contains($request->url(), 'subscribed_apps') || str_contains($request->url(), '/register'));
    }

    public function test_the_provider_refuses_an_invalid_foreign_or_underprivileged_token(): void
    {
        foreach ([
            [OnboardingFailure::TokenInvalid, ['is_valid' => false]],
            [OnboardingFailure::WrongApp, ['app_id' => '999999']],
            [OnboardingFailure::MissingPermission, ['scopes' => ['whatsapp_business_management']]],
            [OnboardingFailure::WabaInaccessible, ['granular_scopes' => [['scope' => 'whatsapp_business_management', 'target_ids' => ['100000000000777']]]]],
        ] as [$failure, $token]) {
            $this->fakeMeta(token: $token);

            $this->assertSame($failure, app(MetaWhatsAppProvider::class)->verifyOnboarding('code', self::WABA, self::PHONE)->failure, $failure->value);
        }
    }

    public function test_browser_supplied_ids_are_claims_the_provider_must_prove(): void
    {
        $this->fakeMeta(waba: Http::response(['error' => ['code' => 100, 'message' => 'secret detail']], 400));
        $this->assertSame(OnboardingFailure::WabaInaccessible, app(MetaWhatsAppProvider::class)->verifyOnboarding('code', self::WABA, self::PHONE)->failure);

        // The claimed number is not the WABA's: refused, with no fallback to a number it does have.
        $this->fakeMeta(numbers: [['id' => '200000000000055', 'display_phone_number' => '+234 700 000 5555']]);
        $this->assertSame(OnboardingFailure::PhoneNotInWaba, app(MetaWhatsAppProvider::class)->verifyOnboarding('code', self::WABA, self::PHONE)->failure);

        $this->assertSame(OnboardingFailure::ExchangeFailed, app(MetaWhatsAppProvider::class)->verifyOnboarding('code', 'not-numeric', self::PHONE)->failure);
    }

    public function test_the_provider_registers_subscribes_and_never_logs_a_secret(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message.json_encode($event->context);
        });
        $this->fakeMeta(register: Http::response(['error' => ['code' => 133016, 'message' => 'contains business-token-1']], 400));
        $provider = app(MetaWhatsAppProvider::class);

        $provider->verifyOnboarding('code-xyz', self::WABA, self::PHONE);
        $this->assertTrue($provider->subscribeApp('business-token-1', self::WABA));
        $this->assertSame(OnboardingFailure::RegistrationRateLimited, $provider->registerPhoneNumber('business-token-1', self::PHONE, self::PIN));

        Http::assertSent(fn (HttpRequest $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/v26.0/'.self::WABA.'/subscribed_apps'));
        Http::assertSent(fn (HttpRequest $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/v26.0/'.self::PHONE.'/register')
            && $request['messaging_product'] === 'whatsapp' && $request['pin'] === self::PIN);

        $this->assertNotEmpty($logged, 'the failure is still diagnosable');
        foreach (['business-token-1', 'code-xyz', self::PIN, 'app-secret-never-shown', 'verify-token-never-shown'] as $secret) {
            $this->assertStringNotContainsString($secret, implode("\n", $logged));
        }
    }

    public function test_the_graph_version_is_validated_and_never_silently_replaced(): void
    {
        foreach (['v26', '26.0', 'v26.0/../x', '', null] as $invalid) {
            config(['whatsapp.graph_version' => $invalid]);
            $this->assertFalse(app(MetaWhatsAppProvider::class)->isConfigured(), var_export($invalid, true));
        }

        $this->expectException(RuntimeException::class);
        GraphVersion::configured();
    }

    /* ------------------------------------------------------------ default automations */

    public function test_a_business_without_automations_receives_the_defaults_once_and_they_are_never_overwritten(): void
    {
        $this->assertSame(0, DB::table('whatsapp_automations')->where('business_id', $this->b->id)->count());

        $this->provider->accessible = ['100000000000002' => ['200000000000002']];
        $this->complete($this->adminB, $this->start($this->adminB)->json('state'), ['waba_id' => '100000000000002', 'phone_number_id' => '200000000000002'])->assertOk();

        $keys = DB::table('whatsapp_automations')->where('business_id', $this->b->id)->orderBy('key')->pluck('enabled', 'key')->all();
        $this->assertSame(['low_stock' => 0, 'pickup_reminder' => 0, 'post_purchase' => 0, 'welcome' => 0], $keys, 'all four, all switched off');

        DB::table('whatsapp_automations')->where('business_id', $this->b->id)->where('key', 'welcome')->update(['body' => 'Bravo custom']);
        $defaults = app(EnsureDefaultAutomations::class);
        $this->assertSame(0, $defaults->for($this->b) + $defaults->for($this->b));
        $this->assertSame('Bravo custom', DB::table('whatsapp_automations')->where('business_id', $this->b->id)->where('key', 'welcome')->value('body'));
        $this->assertSame(4, DB::table('whatsapp_automations')->where('business_id', $this->b->id)->count());
        $this->assertSame(4, DB::table('whatsapp_automations')->where('business_id', $this->a->id)->count(), 'another Business is untouched');
    }

    /* -------------------------------------------------------------------- disconnect */

    public function test_disconnecting_affects_only_the_operators_business_and_stops_its_sends(): void
    {
        $this->connectWhatsApp($this->a, self::WABA, self::PHONE, 'TOKEN_A');
        app(EnsureDefaultAutomations::class)->for($this->b);
        $this->connectWhatsApp($this->b, '100000000000002', '200000000000002', 'TOKEN_B');
        $waitingA = $this->queuedMessage(WhatsAppConnection::forBusiness($this->a), $this->approvedAutomation($this->a), 'dc:a');
        $waitingB = $this->queuedMessage(WhatsAppConnection::forBusiness($this->b), $this->approvedAutomation($this->b), 'dc:b');
        $before = (array) DB::table('whatsapp_connection')->where('business_id', $this->b->id)->first();

        $this->actingAs($this->adminA)->post(route('whatsapp.automation.connection.disconnect'))->assertRedirect();

        $alpha = DB::table('whatsapp_connection')->where('business_id', $this->a->id)->first();
        $this->assertSame(['disconnected', null], [$alpha->status, $alpha->access_token], 'the credential is erased');
        $this->assertSame([self::WABA, self::PHONE], [$alpha->waba_id, $alpha->phone_number_id], 'the account stays this Business\'s');
        $this->assertSame(['failed', 'disconnected'], [DB::table('whatsapp_messages')->where('id', $waitingA->id)->value('status'), DB::table('whatsapp_messages')->where('id', $waitingA->id)->value('failure_code')]);
        $this->assertSame([self::WABA], $this->provider->unsubscribed);
        $this->assertSame($this->a->id, DB::table('audit_logs')->where('action', 'whatsapp_disconnected')->value('business_id'));

        $this->assertEquals($before, (array) DB::table('whatsapp_connection')->where('business_id', $this->b->id)->first(), 'B is byte-identical');
        $this->assertSame('queued', DB::table('whatsapp_messages')->where('id', $waitingB->id)->value('status'));

        // A disconnected Business neither queues nor sends; B still does.
        $welcomeA = app(CurrentBusiness::class)->run($this->a, fn () => WhatsAppAutomation::forKey(WhatsAppAutomation::WELCOME));
        $this->assertNull(app(QueueWhatsAppMessage::class)->toBusinessAlertNumber($welcomeA, '+2348030000001', [], 'dc:after'));
        // As the scheduler runs it: no one is signed in.
        auth()->forgetGuards();
        $this->artisan('inventra:dispatch-whatsapp-messages')->assertSuccessful();
        $this->assertSame(['TOKEN_B'], array_column($this->provider->sent, 'token'));
        $this->assertSame('failed', DB::table('whatsapp_messages')->where('id', $waitingA->id)->value('status'), 'a cancelled message stays cancelled');

        // Disconnecting again is a harmless no-op.
        $this->actingAs($this->adminA)->post(route('whatsapp.automation.connection.disconnect'))->assertRedirect();
        $this->assertSame([self::WABA], $this->provider->unsubscribed);
    }

    /* ----------------------------------------------------------------- template sync */

    public function test_template_status_comes_only_from_the_businesss_own_waba_as_meta_reports_it(): void
    {
        $this->connectWhatsApp($this->a, self::WABA, self::PHONE, 'TOKEN_A');
        $this->provider->templates = [
            self::WABA => [
                ['name' => 'inventra_welcome', 'language' => 'en', 'status' => 'APPROVED'],
                ['name' => 'inventra_post_purchase', 'language' => 'en_GB', 'status' => 'PENDING'],
                ['name' => 'inventra_pickup_reminder', 'language' => 'en', 'status' => 'REJECTED'],
            ],
            // Another Business's WABA approving the same name must never count for this one.
            '100000000000002' => [['name' => 'inventra_low_stock', 'language' => 'en', 'status' => 'APPROVED']],
        ];

        $this->actingAs($this->adminA)->post(route('whatsapp.automation.templates.sync'))->assertRedirect();

        $state = DB::table('whatsapp_automations')->where('business_id', $this->a->id)->get()->keyBy('key');
        $this->assertSame(['inventra_welcome', 'en', 'APPROVED'], [$state['welcome']->template_name, $state['welcome']->template_language, $state['welcome']->template_status]);
        $this->assertSame(['inventra_post_purchase', 'en_GB', 'PENDING'], [$state['post_purchase']->template_name, $state['post_purchase']->template_language, $state['post_purchase']->template_status]);
        $this->assertSame('REJECTED', $state['pickup_reminder']->template_status);
        $this->assertSame([null, null], [$state['low_stock']->template_name, $state['low_stock']->template_status], 'no template, no approval');

        // A template Meta stops listing loses its status, which is what stops it sending.
        $this->provider->templates[self::WABA] = [];
        app(SyncWhatsAppTemplates::class)->execute($this->a);
        $this->assertNull(DB::table('whatsapp_automations')->where('business_id', $this->a->id)->where('key', 'welcome')->value('template_status'));

        // Unreadable Meta changes nothing and says so.
        $this->provider->templatesUnavailable = true;
        $this->assertNull(app(SyncWhatsAppTemplates::class)->execute($this->a));
    }

    /* ----------------------------------------------------------------------- helpers */

    private function start(User $admin): TestResponse
    {
        return $this->asBrowserOf($admin)->postJson(route('whatsapp.automation.connection.start'));
    }

    /** @param array<string, mixed> $overrides */
    private function complete(User $admin, ?string $state, array $overrides = [], ?string $session = null): TestResponse
    {
        return $this->asBrowserOf($admin, $session)->postJson(route('whatsapp.automation.connection.complete'), array_merge([
            'state' => $state, 'code' => 'meta-code', 'waba_id' => self::WABA, 'phone_number_id' => self::PHONE, 'pin' => self::PIN,
        ], $overrides));
    }

    /** Each operator's own browser session, kept across their requests as the page does. */
    private function asBrowserOf(User $admin, ?string $session = null): static
    {
        $session ??= $this->sessions[$admin->id] ??= Str::random(40);

        return $this->actingAs($admin)->withCredentials()->withCookie(config('session.cookie'), $session);
    }

    private function assertPinNeverPersisted(): void
    {
        foreach (['whatsapp_onboarding_attempts', 'whatsapp_connection', 'audit_logs', 'security_events', 'sessions'] as $table) {
            foreach (DB::table($table)->get() as $row) {
                $this->assertStringNotContainsString(self::PIN, json_encode($row).base64_decode((string) ($row->payload ?? '')), "{$table} must never hold the PIN");
            }
        }
    }

    /**
     * Meta's documented responses, served locally. Anything not faked here is refused, so a test
     * can never reach the real Graph API.
     *
     * @param  array<string, mixed>  $token  overrides of debug_token's data
     * @param  list<array<string, string>>|null  $numbers
     */
    private function fakeMeta(array $token = [], mixed $waba = null, ?array $numbers = null, mixed $register = null): void
    {
        $this->app->forgetInstance(MetaWhatsAppProvider::class);
        // A fresh client each time: fakes accumulate, and an earlier stub would otherwise answer first.
        Http::swap(new HttpFactory);
        Http::preventStrayRequests();
        Http::fake([
            'graph.facebook.com/v26.0/oauth/access_token*' => Http::response(['access_token' => 'business-token-1', 'token_type' => 'bearer']),
            'graph.facebook.com/v26.0/debug_token*' => Http::response(['data' => array_merge([
                'app_id' => '555000111', 'is_valid' => true, 'expires_at' => 0,
                'scopes' => ['whatsapp_business_management', 'whatsapp_business_messaging'],
                'granular_scopes' => [['scope' => 'whatsapp_business_management', 'target_ids' => [self::WABA]]],
            ], $token)]),
            'graph.facebook.com/v26.0/'.self::WABA.'/phone_numbers*' => Http::response(['data' => $numbers ?? [
                ['id' => self::PHONE, 'display_phone_number' => '+234 700 000 1234', 'verified_name' => 'Alpha Spares'],
            ]]),
            'graph.facebook.com/v26.0/'.self::WABA.'/subscribed_apps' => Http::response(['success' => true]),
            'graph.facebook.com/v26.0/'.self::PHONE.'/register' => $register ?? Http::response(['success' => true]),
            'graph.facebook.com/v26.0/'.self::WABA.'*' => $waba ?? Http::response(['id' => self::WABA, 'name' => 'Alpha WABA']),
        ]);
    }
}
