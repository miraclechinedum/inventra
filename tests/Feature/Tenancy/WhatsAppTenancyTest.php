<?php

namespace Tests\Feature\Tenancy;

use App\Actions\WhatsAppAutomation\QueueWhatsAppMessage;
use App\Actions\WhatsAppAutomation\RetryWhatsAppMessage;
use App\Actions\WhatsAppAutomation\UpdateWhatsAppAutomation;
use App\Actions\WhatsAppAutomation\WhatsAppAutomationTriggers;
use App\Contracts\WhatsAppConnectionProvider;
use App\Enums\UserRole;
use App\Models\Business;
use App\Models\BusinessSetting;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Models\WhatsAppAutomation;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Settings\BusinessSettings;
use App\Support\WhatsApp\OnboardingFailure;
use App\Tenancy\BusinessContextException;
use App\Tenancy\CurrentBusiness;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use LogicException;
use Tests\Concerns\BuildsWhatsAppWorld;
use Tests\Fakes\FakeWhatsAppProvider;
use Tests\TestCase;

/**
 * Two Businesses, each with its own WhatsApp connection, automations, staff, customers and
 * messages. Every name, number and credential carries its Business ("Alpha …", "Bravo …", PHONE_A,
 * TOKEN_B), so a leak or a crossed send shows up as text. The provider is a fake; nothing calls Meta.
 */
class WhatsAppTenancyTest extends TestCase
{
    private ?string $browserSession = null;

    use BuildsWhatsAppWorld, RefreshDatabase;

    private const WABA_A = '100000000000001';

    private const WABA_B = '100000000000002';

    private FakeWhatsAppProvider $provider;

    private Business $a;

    private Business $b;

    /** @var array<string, User> */
    private array $staffA;

    /** @var array<string, User> */
    private array $staffB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new FakeWhatsAppProvider;
        $this->app->instance(WhatsAppConnectionProvider::class, $this->provider);
        config(['whatsapp.app_secret' => 'test-secret']);

        // Alpha is the installation Business, whose connection and automations the migration owns.
        $this->a = Business::query()->orderBy('id')->firstOrFail();
        $this->a->forceFill(['name' => 'Alpha Spares'])->save();
        BusinessSetting::query()->where('business_id', $this->a->id)->update(['business_name' => 'Alpha Spares', 'manager_alert_number' => '+2348030000001']);
        $this->b = Business::factory()->create(['name' => 'Bravo Hardware']);
        BusinessSetting::query()->where('business_id', $this->b->id)->update(['business_name' => 'Bravo Hardware', 'manager_alert_number' => '+2348030000002']);
        app(BusinessSettings::class)->forget();
        $this->automationsFor($this->b);

        $this->staffA = $this->staff($this->a, 'Alpha', '1');
        $this->staffB = $this->staff($this->b, 'Bravo', '2');
    }

    /* ------------------------------------------------------------------ connection */

    public function test_each_administrator_sees_only_their_own_connection(): void
    {
        $this->connectBoth();

        $this->actingAs($this->staffA['admin'])->get(route('whatsapp.automation.index'))->assertOk()
            ->assertViewHas('connection', fn (WhatsAppConnection $connection): bool => $connection->business_id === $this->a->id && $connection->waba_id === self::WABA_A)
            ->assertDontSee('+2347000000002')->assertDontSee(self::WABA_B)->assertDontSee('TOKEN_');
        $this->actingAs($this->staffA['admin'])->get(route('settings.business.edit'))->assertOk()
            ->assertSee('+2347000000001')->assertDontSee('+2347000000002');
        $this->actingAs($this->staffB['admin'])->get(route('settings.business.edit'))->assertOk()
            ->assertSee('+2347000000002')->assertDontSee('+2347000000001');

        $this->assertSame(self::WABA_B, $this->inBusiness($this->b, fn () => WhatsAppConnection::forCurrentBusiness()->waba_id));
    }

    public function test_a_business_without_a_connection_is_disconnected_and_never_borrows_one(): void
    {
        $this->connectWhatsApp($this->a, self::WABA_A, 'PHONE_A', 'TOKEN_A');

        $bravo = WhatsAppConnection::forBusiness($this->b);
        $this->assertFalse($bravo->exists, 'reading must not create a connection');
        $this->assertFalse($bravo->isConnected());
        $this->assertNull($bravo->waba_id);
        $this->assertSame(1, DB::table('whatsapp_connection')->count());
    }

    public function test_onboarding_cannot_claim_or_overwrite_another_businesss_account(): void
    {
        $this->connectWhatsApp($this->b, self::WABA_B, '200000000000002', 'TOKEN_B');
        $before = $this->connectionRow($this->b);
        // Meta would let A's token see both accounts: only Inventra's ownership check stands between.
        $this->provider->accessible = [self::WABA_A => ['200000000000001', '200000000000002'], self::WABA_B => ['200000000000001', '200000000000002']];

        foreach ([['waba_id' => self::WABA_B, 'phone_number_id' => '200000000000001'], ['waba_id' => self::WABA_A, 'phone_number_id' => '200000000000002']] as $claim) {
            $this->onboardAs($this->staffA['admin'], $claim)
                ->assertUnprocessable()->assertJsonPath('errors.connection.0', OnboardingFailure::AccountUnavailable->message());
        }

        $this->assertEquals($before, $this->connectionRow($this->b), 'B must be byte-identical');
        $this->assertFalse(WhatsAppConnection::forBusiness($this->a)->isConnected());
        $this->assertSame([], $this->provider->subscribed, 'nothing may be subscribed or registered for an account held elsewhere');
        $this->assertSame([], $this->provider->registered);
    }

    public function test_onboarding_writes_only_the_operators_business(): void
    {
        $this->connectWhatsApp($this->b, self::WABA_B, '200000000000002', 'TOKEN_B');
        $before = $this->connectionRow($this->b);

        $this->onboardAs($this->staffA['admin'], ['waba_id' => self::WABA_A, 'phone_number_id' => '200000000000001'])->assertOk();

        $alpha = WhatsAppConnection::forBusiness($this->a);
        $this->assertSame([self::WABA_A, '200000000000001', true], [$alpha->waba_id, $alpha->phone_number_id, $alpha->isConnected()]);
        $this->assertEquals($before, $this->connectionRow($this->b));
        $this->assertSame($this->a->id, DB::table('audit_logs')->where('action', 'whatsapp_connected')->value('business_id'));
        $this->assertSame(1, DB::table('whatsapp_connection')->where('business_id', $this->a->id)->count());
    }

    public function test_the_database_holds_one_connection_per_business_and_one_business_per_account(): void
    {
        $this->connectBoth();

        foreach ([
            ['business_id' => $this->a->id, 'waba_id' => '100000000000009', 'phone_number_id' => 'PHONE_Z'],
            ['business_id' => Business::factory()->create()->id, 'waba_id' => self::WABA_A, 'phone_number_id' => 'PHONE_Z'],
            ['business_id' => Business::factory()->create()->id, 'waba_id' => '100000000000009', 'phone_number_id' => 'PHONE_A'],
        ] as $row) {
            try {
                DB::table('whatsapp_connection')->insert($row + ['singleton_key' => 'whatsapp', 'status' => 'disconnected']);
                $this->fail('A second connection, or a claimed account, must be refused: '.json_encode($row));
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_test_send_goes_out_only_through_the_senders_business(): void
    {
        $this->connectBoth();
        $alpha = $this->approvedAutomation($this->a);
        $this->approvedAutomation($this->b);

        $this->actingAs($this->staffA['admin'])->postJson(route('whatsapp.automation.test', $alpha))->assertOk();

        $this->assertCount(1, $this->provider->sent);
        $this->assertSame(['PHONE_A', 'TOKEN_A'], [$this->provider->sent[0]['phone_number_id'], $this->provider->sent[0]['token']]);
        $this->assertSame([$this->a->id], DB::table('whatsapp_messages')->distinct()->pluck('business_id')->all());
    }

    /* ----------------------------------------------------------------- automations */

    public function test_every_business_has_its_own_automation_for_each_key(): void
    {
        $keys = [WhatsAppAutomation::WELCOME, WhatsAppAutomation::POST_PURCHASE, WhatsAppAutomation::PICKUP_REMINDER, WhatsAppAutomation::LOW_STOCK];

        foreach ([$this->a, $this->b] as $business) {
            $this->assertEqualsCanonicalizing($keys, DB::table('whatsapp_automations')->where('business_id', $business->id)->pluck('key')->all());
            $this->assertEqualsCanonicalizing($keys, $this->inBusiness($business, fn () => WhatsAppAutomation::query()->pluck('key')->all()));
        }

        $this->expectException(QueryException::class);
        DB::table('whatsapp_automations')->insert(['business_id' => $this->a->id, 'key' => WhatsAppAutomation::WELCOME, 'body' => 'x']);
    }

    public function test_configuration_routes_act_only_on_the_operators_automations(): void
    {
        $bravo = $this->inBusiness($this->b, fn () => WhatsAppAutomation::forKey(WhatsAppAutomation::WELCOME));
        $before = (array) DB::table('whatsapp_automations')->where('id', $bravo->id)->first();

        $this->actingAs($this->staffA['admin'])->postJson(route('whatsapp.automation.toggle', $bravo->id), ['enabled' => true])->assertNotFound();
        $this->actingAs($this->staffA['admin'])->putJson(route('whatsapp.automation.update', $bravo->id), ['body' => 'Hijacked'])->assertNotFound();
        $this->actingAs($this->staffA['admin'])->postJson(route('whatsapp.automation.test', $bravo->id))->assertNotFound();
        $this->assertEquals($before, (array) DB::table('whatsapp_automations')->where('id', $bravo->id)->first());

        $alpha = WhatsAppAutomation::forKey(WhatsAppAutomation::WELCOME);
        $this->actingAs($this->staffA['admin'])->postJson(route('whatsapp.automation.toggle', $alpha), ['enabled' => true])->assertOk();
        $this->actingAs($this->staffA['admin'])->putJson(route('whatsapp.automation.update', $alpha), ['body' => 'Hello {{customer_name}}'])->assertOk();
        $this->assertEquals($before, (array) DB::table('whatsapp_automations')->where('id', $bravo->id)->first(), 'A\'s change must leave B identical');
        $this->assertFalse(Gate::forUser($this->staffA['admin'])->allows('configure', $bravo));
    }

    public function test_automation_recipients_are_only_ever_the_same_businesss_staff(): void
    {
        $alpha = WhatsAppAutomation::forKey(WhatsAppAutomation::LOW_STOCK);

        app(UpdateWhatsAppAutomation::class)->save($this->staffA['admin'], $alpha, $alpha->body,
            recipientIds: [$this->staffA['manager']->id, $this->staffB['manager']->id]);
        $this->assertSame([$this->staffA['manager']->id], $alpha->recipients()->pluck('users.id')->all());
        $this->assertSame([$this->a->id], DB::table('whatsapp_automation_recipients')->distinct()->pluck('business_id')->all());

        $this->expectException(QueryException::class);
        $alpha->syncRecipients([$this->staffB['manager']->id]);
    }

    /* -------------------------------------------------------------------- messages */

    public function test_triggers_queue_each_message_in_its_subjects_business(): void
    {
        $this->connectBoth();
        $this->approvedAutomation($this->a);
        $this->approvedAutomation($this->b);
        $this->approvedAutomation($this->a, WhatsAppAutomation::LOW_STOCK);
        $this->approvedAutomation($this->b, WhatsAppAutomation::LOW_STOCK);
        $triggers = app(WhatsAppAutomationTriggers::class);

        $customerB = $this->consentingCustomer($this->b, 'Bravo');
        $customerA = $this->consentingCustomer($this->a, 'Alpha');
        $triggers->customerCreated($customerB);
        $triggers->customerCreated($customerA);

        $productB = $this->inBusiness($this->b, fn () => Product::factory()->forBusiness($this->b)->create(['current_stock' => '1.000', 'reorder_level' => '5.000']));
        $productA = $this->inBusiness($this->a, fn () => Product::factory()->forBusiness($this->a)->create(['current_stock' => '1.000', 'reorder_level' => '5.000']));
        $triggers->stockChanged($productB);
        $triggers->stockChanged($productA);

        foreach ([[$this->a, $customerA, $productA, '+2348030000001', 'Alpha Spares'], [$this->b, $customerB, $productB, '+2348030000002', 'Bravo Hardware']] as [$business, $customer, $product, $alertNumber, $name]) {
            $welcome = DB::table('whatsapp_messages')->where('customer_id', $customer->id)->sole();
            $lowStock = DB::table('whatsapp_messages')->where('type', WhatsAppAutomation::LOW_STOCK)->where('subject_id', $product->id)->sole();
            $connection = WhatsAppConnection::forBusiness($business)->id;

            $this->assertSame([$business->id, $connection], [$welcome->business_id, $welcome->whatsapp_connection_id]);
            $this->assertSame([$business->id, $connection, $alertNumber, $name], [$lowStock->business_id, $lowStock->whatsapp_connection_id, $lowStock->destination_phone, $lowStock->recipient_name]);
            $this->assertStringContainsString($name, $welcome->body);
            $this->assertSame($business->id, DB::table('whatsapp_low_stock_episodes')->where('product_id', $product->id)->value('business_id'));
        }
    }

    public function test_a_message_can_never_combine_two_businesses(): void
    {
        $this->connectBoth();
        $alpha = $this->approvedAutomation($this->a);
        $queue = app(QueueWhatsAppMessage::class);
        $customerB = $this->consentingCustomer($this->b, 'Bravo');
        $saleB = $this->inBusiness($this->b, fn () => Sale::factory()->forBusiness($this->b)->create());

        foreach ([
            'customer of another business' => fn () => $queue->toCustomer($alpha, $customerB, [], 'probe:1'),
            'staff of another business' => fn () => $queue->toStaff($alpha, $this->staffB['manager'], [], 'probe:2'),
            'subject of another business' => fn () => $queue->toCustomer($alpha, $this->consentingCustomer($this->a, 'Alpha'), [], 'probe:3', $saleB),
        ] as $case => $attempt) {
            try {
                $attempt();
                $this->fail("Queueing must refuse a {$case}.");
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }

        // Directly, too: a row naming another Business's staff is refused before it is written.
        $message = new WhatsAppMessage;
        $message->forceFill(['business_id' => $this->a->id, 'user_id' => $this->staffB['manager']->id, 'type' => 'test', 'recipient_name' => 'x',
            'destination_phone' => '+2348030000009', 'body' => 'x', 'idempotency_key' => 'probe:4', 'origin' => 'test', 'status' => 'queued', 'queued_at' => now()]);

        $this->expectException(LogicException::class);
        $message->save();
    }

    public function test_the_message_log_is_confined_to_the_business(): void
    {
        $this->connectBoth();
        $messageA = $this->queuedMessage(WhatsAppConnection::forBusiness($this->a), $this->approvedAutomation($this->a), 'log:a');
        $messageB = $this->queuedMessage(WhatsAppConnection::forBusiness($this->b), $this->approvedAutomation($this->b), 'log:b');
        DB::table('whatsapp_messages')->where('id', $messageB->id)->update(['recipient_name' => 'Bravo Buyer', 'failure_reason' => 'Bravo secret failure']);
        DB::table('whatsapp_messages')->where('id', $messageA->id)->update(['recipient_name' => 'Alpha Buyer']);

        $this->actingAs($this->staffA['manager'])->get(route('whatsapp.logs.index', ['range' => 'all']))->assertOk()
            ->assertSee('Alpha Buyer')->assertDontSee('Bravo Buyer')
            ->assertViewHas('summary', fn (array $summary): bool => $summary['pending'] === 1)
            ->assertViewHas('messages', fn ($page): bool => $page->total() === 1);
        $this->actingAs($this->staffA['manager'])->get(route('whatsapp.logs.index', ['range' => 'all', 'message' => $messageB->id]))->assertOk()
            ->assertViewHas('selected', null)->assertDontSee('Bravo secret failure');
        $this->actingAs($this->staffA['admin'])->get(route('whatsapp.automation.index'))->assertOk()->assertDontSee('Bravo Buyer');

        app(CurrentBusiness::class)->forget();
        $this->expectException(BusinessContextException::class);
        WhatsAppMessage::query()->count();
    }

    public function test_a_business_cannot_retry_another_businesss_message(): void
    {
        $this->connectBoth();
        $messageB = $this->queuedMessage(WhatsAppConnection::forBusiness($this->b), $this->approvedAutomation($this->b), 'retry:b');
        DB::table('whatsapp_messages')->where('id', $messageB->id)->update(['status' => 'failed', 'failed_at' => now()]);
        $rows = DB::table('whatsapp_messages')->count();

        $this->actingAs($this->staffA['admin'])->post(route('whatsapp.messages.retry', $messageB->id))->assertNotFound();

        try {
            app(RetryWhatsAppMessage::class)->execute($this->staffA['admin'], WhatsAppMessage::acrossBusinesses()->findOrFail($messageB->id));
            $this->fail('The action must not retry another Business\'s message, however it was loaded.');
        } catch (ModelNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame($rows, DB::table('whatsapp_messages')->count());
        $this->assertSame([], $this->provider->sent);
        $this->assertFalse(Gate::forUser($this->staffA['admin'])->allows('retry', [WhatsAppAutomation::class, WhatsAppMessage::acrossBusinesses()->find($messageB->id)]));
    }

    public function test_a_retry_stays_in_the_messages_business(): void
    {
        $this->connectBoth();
        $messageA = $this->queuedMessage(WhatsAppConnection::forBusiness($this->a), $this->approvedAutomation($this->a), 'retry:a');
        DB::table('whatsapp_messages')->where('id', $messageA->id)->update(['status' => 'failed', 'failed_at' => now()]);

        $this->actingAs($this->staffA['admin'])->post(route('whatsapp.messages.retry', $messageA->id))->assertRedirect();

        $retry = DB::table('whatsapp_messages')->where('retry_of_id', $messageA->id)->sole();
        $this->assertSame($this->a->id, $retry->business_id);
        $this->assertSame(['PHONE_A', 'TOKEN_A'], [$this->provider->sent[0]['phone_number_id'], $this->provider->sent[0]['token']]);
    }

    /* -------------------------------------------------------------------- dispatch */

    public function test_one_dispatch_run_sends_each_message_through_its_own_business(): void
    {
        $this->connectBoth();
        $alpha = $this->approvedAutomation($this->a);
        $bravo = $this->approvedAutomation($this->b);
        $messageA = $this->queuedMessage(WhatsAppConnection::forBusiness($this->a), $alpha, 'dispatch:a');
        $messageB = $this->queuedMessage(WhatsAppConnection::forBusiness($this->b), $bravo, 'dispatch:b');

        $this->artisan('inventra:dispatch-whatsapp-messages')->assertSuccessful();

        $sent = collect($this->provider->sent)->keyBy('to');
        $this->assertSame(['PHONE_A', 'TOKEN_A'], [$sent[$messageA->destination_phone]['phone_number_id'], $sent[$messageA->destination_phone]['token']]);
        $this->assertSame(['PHONE_B', 'TOKEN_B'], [$sent[$messageB->destination_phone]['phone_number_id'], $sent[$messageB->destination_phone]['token']]);
        $this->assertSame('PHONE_B', DB::table('whatsapp_messages')->where('id', $messageB->id)->value('sender_phone_number_id'));

        // Alpha's connection fails. Its message waits; Bravo's still goes, through Bravo only.
        DB::table('whatsapp_connection')->where('business_id', $this->a->id)->update(['status' => 'disconnected', 'verified_at' => null]);
        $waitingA = $this->queuedMessage(WhatsAppConnection::forBusiness($this->a), $alpha, 'dispatch:a2');
        $nextB = $this->queuedMessage(WhatsAppConnection::forBusiness($this->b), $bravo, 'dispatch:b2');
        $this->provider->sent = [];

        $this->artisan('inventra:dispatch-whatsapp-messages')->assertSuccessful();

        $this->assertSame([$nextB->destination_phone], array_column($this->provider->sent, 'to'));
        $this->assertSame('PHONE_B', $this->provider->sent[0]['phone_number_id']);
        $this->assertSame([WhatsAppMessage::STATUS_QUEUED, null], array_values((array) DB::table('whatsapp_messages')->where('id', $waitingA->id)->first(['status', 'dispatch_claimed_at'])));
    }

    /* --------------------------------------------------------------------- webhook */

    public function test_webhooks_route_only_through_the_named_account_and_number(): void
    {
        $this->connectBoth();
        $messageA = $this->sentMessage($this->a, 'wamid.ALPHA', 'PHONE_A');
        $messageB = $this->sentMessage($this->b, 'wamid.BRAVO', 'PHONE_B');

        // 1–2. Each Business's own account and number advance its own message.
        $this->whatsappStatusWebhook(self::WABA_A, 'PHONE_A', 'wamid.ALPHA', 'delivered')->assertOk();
        $this->whatsappStatusWebhook(self::WABA_B, 'PHONE_B', 'wamid.BRAVO', 'delivered')->assertOk();
        $this->assertSame(['delivered', 'delivered'], [$this->messageStatus($messageA), $this->messageStatus($messageB)]);

        // 3. A's account carrying B's message id cannot touch B.
        $this->whatsappStatusWebhook(self::WABA_A, 'PHONE_A', 'wamid.BRAVO', 'read')->assertOk();
        // 4. A known account reporting a number it does not have connected writes nothing.
        $this->whatsappStatusWebhook(self::WABA_A, 'PHONE_B', 'wamid.ALPHA', 'read')->assertOk();
        $this->whatsappStatusWebhook(self::WABA_B, 'PHONE_A', 'wamid.BRAVO', 'read')->assertOk();
        // 5. An unknown account is acknowledged and changes nothing.
        $this->whatsappStatusWebhook('999999999999999', 'PHONE_A', 'wamid.ALPHA', 'read')->assertOk()->assertSee('EVENT_RECEIVED');
        // No account at all.
        $this->whatsappStatusWebhook('', 'PHONE_A', 'wamid.ALPHA', 'read')->assertOk();

        $this->assertSame(['delivered', 'delivered'], [$this->messageStatus($messageA), $this->messageStatus($messageB)]);
        $this->assertNull(DB::table('whatsapp_messages')->where('id', $messageB->id)->value('read_at'));

        // 6. Provider ids are Meta's own global identifiers, so the schema keeps them unique: the
        //    same id cannot exist in two Businesses to be confused in the first place.
        $this->expectException(QueryException::class);
        DB::table('whatsapp_messages')->where('id', $this->queuedMessage(WhatsAppConnection::forBusiness($this->b),
            $this->approvedAutomation($this->b), 'dup:b')->id)->update(['provider_message_id' => 'wamid.ALPHA']);
    }

    public function test_a_forged_signature_is_still_refused_before_any_routing(): void
    {
        $this->connectBoth();
        $messageA = $this->sentMessage($this->a, 'wamid.ALPHA', 'PHONE_A');

        $this->whatsappStatusWebhook(self::WABA_A, 'PHONE_A', 'wamid.ALPHA', 'delivered', 'wrong-secret')->assertForbidden();

        $this->assertSame('sent', $this->messageStatus($messageA));
    }

    /* --------------------------------------------------------------------- helpers */

    /** @return array<string, User> */
    private function staff(Business $business, string $prefix, string $digit): array
    {
        return [
            'admin' => User::factory()->forBusiness($business)->create(['role' => UserRole::Admin, 'name' => "{$prefix} Admin", 'phone' => "+234803100000{$digit}"]),
            'manager' => User::factory()->forBusiness($business)->create(['role' => UserRole::Manager, 'name' => "{$prefix} Manager", 'phone' => "+234803200000{$digit}"]),
        ];
    }

    /** @param array<string, string> $claim */
    private function onboardAs(User $admin, array $claim): TestResponse
    {
        // One browser session across both requests, exactly as the page runs it.
        $this->withCredentials()->withCookie(config('session.cookie'), $this->browserSession ??= Str::random(40));
        $state = $this->actingAs($admin)->postJson(route('whatsapp.automation.connection.start'))->assertOk()->json('state');

        return $this->actingAs($admin)->postJson(route('whatsapp.automation.connection.complete'), $claim + [
            'state' => $state, 'code' => 'exchange', 'pin' => '123456',
        ]);
    }

    private function connectBoth(): void
    {
        $this->connectWhatsApp($this->a, self::WABA_A, 'PHONE_A', 'TOKEN_A')->forceFill(['display_phone_number' => '+234 700 000 0001'])->save();
        $this->connectWhatsApp($this->b, self::WABA_B, 'PHONE_B', 'TOKEN_B')->forceFill(['display_phone_number' => '+234 700 000 0002'])->save();
    }

    private function consentingCustomer(Business $business, string $prefix): Customer
    {
        return $this->inBusiness($business, fn () => Customer::factory()->forBusiness($business)->create([
            'first_name' => $prefix, 'last_name' => 'Buyer', 'phone' => '+23481'.random_int(10000000, 99999999),
            'is_active' => true, 'whatsapp_opt_in' => true, 'whatsapp_opt_in_at' => now(),
        ]));
    }

    private function sentMessage(Business $business, string $providerId, string $sender): WhatsAppMessage
    {
        $message = $this->queuedMessage(WhatsAppConnection::forBusiness($business), $this->approvedAutomation($business), 'hook:'.$providerId);
        DB::table('whatsapp_messages')->where('id', $message->id)->update([
            'status' => 'sent', 'sent_at' => now(), 'provider_message_id' => $providerId, 'sender_phone_number_id' => $sender,
        ]);

        return $message;
    }

    private function messageStatus(WhatsAppMessage $message): string
    {
        return (string) DB::table('whatsapp_messages')->where('id', $message->id)->value('status');
    }

    /** @return array<string, mixed> the raw row, ciphertext included, for byte-for-byte comparison */
    private function connectionRow(Business $business): array
    {
        return (array) DB::table('whatsapp_connection')->where('business_id', $business->id)->first();
    }

    private function inBusiness(Business $business, \Closure $work): mixed
    {
        return app(CurrentBusiness::class)->run($business, $work);
    }
}
