<?php

namespace Tests\Feature\WhatsAppAutomation;

use App\Actions\Customer\SetWhatsAppConsent;
use App\Actions\Inventory\AdjustStock;
use App\Actions\Sale\CreateSale;
use App\Actions\Sale\IssueSalePaymentRequest;
use App\Actions\Sale\MarkSaleReadyForPickup;
use App\Actions\Sale\RecordSalePayment;
use App\Actions\WhatsAppAutomation\RetryWhatsAppMessage;
use App\Actions\WhatsAppAutomation\SendWhatsAppTestMessage;
use App\Actions\WhatsAppAutomation\WhatsAppAutomationTriggers;
use App\Contracts\WhatsAppConnectionProvider;
use App\Enums\PaymentStatus;
use App\Enums\SaleStatus;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Models\WhatsAppAutomation;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Settings\BusinessSettings;
use App\Support\Money;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fakes\FakeWhatsAppProvider;
use Tests\TestCase;

/**
 * The four automation triggers, plus retry, test sends and delivery status.
 *
 * The properties under test are the ones that touch real people: a message fires on the event and
 * not on the condition, never twice for one event, never without consent, and is never reported
 * delivered on anything weaker than a webhook.
 */
class WhatsAppTriggerTest extends TestCase
{
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

        WhatsAppConnection::query()->firstOrCreate(['singleton_key' => 'whatsapp'])->forceFill([
            'provider' => 'fake', 'waba_id' => '100000000000001', 'phone_number_id' => '200000000000001',
            'display_phone_number' => '+234 700 000 1234', 'phone_number' => '+2347000001234',
            'access_token' => 'fake-business-token', 'status' => 'connected',
            'verified_at' => now(), 'connected_at' => now(),
        ])->save();
    }

    private function enable(string $key): WhatsAppAutomation
    {
        $automation = WhatsAppAutomation::forKey($key);
        // An approved Meta template is now a precondition for sending anything.
        $automation->update([
            'enabled' => true, 'template_name' => 'inventra_'.$key,
            'template_language' => 'en', 'template_status' => 'APPROVED',
        ]);

        return $automation->refresh();
    }

    private function customer(): Customer
    {
        return Customer::factory()->create([
            'phone' => '+2348090000001', 'is_active' => true,
            'whatsapp_opt_in' => true, 'whatsapp_opt_in_at' => now(), 'whatsapp_opt_out_at' => null,
        ]);
    }

    private function triggers(): WhatsAppAutomationTriggers
    {
        return app(WhatsAppAutomationTriggers::class);
    }

    // ── Post-purchase ───────────────────────────────────────────────────────────────────────────

    public function test_a_paid_sale_queues_one_post_purchase_message(): void
    {
        $this->enable(WhatsAppAutomation::POST_PURCHASE);
        $customer = $this->customer();
        $sale = Sale::factory()->create([
            'customer_id' => $customer->id, 'is_walk_in' => false,
            'status' => SaleStatus::Completed, 'payment_status' => PaymentStatus::Paid,
        ]);

        $this->triggers()->salePaid($sale);
        $this->assertSame(1, WhatsAppMessage::query()->where('type', 'post_purchase')->count());

        // The sale being saved again — a correction, another payment, a retried request — adds none.
        $this->triggers()->salePaid($sale);
        $this->triggers()->salePaid($sale);
        $this->assertSame(1, WhatsAppMessage::query()->where('type', 'post_purchase')->count());
    }

    /** @return array<string, array{PaymentStatus, SaleStatus}> */
    public static function ineligibleSales(): array
    {
        return [
            'partial' => [PaymentStatus::Partial, SaleStatus::Completed],
            'unpaid' => [PaymentStatus::Unpaid, SaleStatus::Completed],
            'voided' => [PaymentStatus::Paid, SaleStatus::Voided],
        ];
    }

    #[DataProvider('ineligibleSales')]
    public function test_only_a_paid_completed_sale_triggers(PaymentStatus $payment, SaleStatus $status): void
    {
        $this->enable(WhatsAppAutomation::POST_PURCHASE);
        $customer = $this->customer();
        $sale = Sale::factory()->create([
            'customer_id' => $customer->id, 'is_walk_in' => false,
            'status' => $status, 'payment_status' => $payment,
        ]);

        $this->triggers()->salePaid($sale);

        $this->assertSame(0, WhatsAppMessage::query()->count());
    }

    public function test_a_walk_in_sale_messages_nobody(): void
    {
        $this->enable(WhatsAppAutomation::POST_PURCHASE);
        $sale = Sale::factory()->create([
            'customer_id' => null, 'is_walk_in' => true,
            'status' => SaleStatus::Completed, 'payment_status' => PaymentStatus::Paid,
        ]);

        $this->triggers()->salePaid($sale);

        $this->assertSame(0, WhatsAppMessage::query()->count());
    }

    public function test_a_sale_paid_in_full_at_creation_queues_one_post_purchase_message(): void
    {
        $this->enable(WhatsAppAutomation::POST_PURCHASE);
        $sale = $this->recordSale($this->customer(), '100.00');

        $message = WhatsAppMessage::query()->where('type', 'post_purchase')->sole();
        $this->assertSame('post_purchase:'.$sale->id, $message->idempotency_key);

        // A paid Sale refuses further payments, so settlement cannot re-trigger it.
        $this->expectException(ValidationException::class);
        try {
            $this->settle($sale, '1.00');
        } finally {
            $this->assertSame(1, WhatsAppMessage::query()->where('type', 'post_purchase')->count());
        }
    }

    public function test_a_partial_payment_does_not_trigger_post_purchase(): void
    {
        $this->enable(WhatsAppAutomation::POST_PURCHASE);
        $sale = $this->recordSale($this->customer(), '0.00');

        $this->settle($sale, '40.00');

        $this->assertSame(PaymentStatus::Partial, $sale->fresh()->payment_status);
        $this->assertSame(0, WhatsAppMessage::query()->where('type', 'post_purchase')->count());
    }

    public function test_the_payment_that_settles_a_sale_triggers_post_purchase_exactly_once(): void
    {
        $this->enable(WhatsAppAutomation::POST_PURCHASE);
        $sale = $this->recordSale($this->customer(), '30.00');
        $this->settle($sale, '30.00');
        $this->assertSame(0, WhatsAppMessage::query()->where('type', 'post_purchase')->count());

        [$token, $sessionId] = $this->settle($sale, '40.00');

        $this->assertSame(PaymentStatus::Paid, $sale->fresh()->payment_status);
        $message = WhatsAppMessage::query()->where('type', 'post_purchase')->sole();
        $this->assertSame('post_purchase:'.$sale->id, $message->idempotency_key);
        $this->assertSame(Money::format('100.00'), $message->template_values['sale_total']);

        // Replaying the settling request returns the original payment and queues nothing more.
        app(RecordSalePayment::class)->execute($this->admin, $sale->fresh(), [
            'amount' => '40.00', 'payment_method' => 'cash', 'note' => null, 'request_token' => $token,
        ], $sessionId);
        $this->assertSame(1, WhatsAppMessage::query()->where('type', 'post_purchase')->count());
    }

    private function recordSale(Customer $customer, string $paid): Sale
    {
        $product = Product::factory()->create(['current_stock' => '10.000', 'selling_price' => '100.00']);

        return app(CreateSale::class)->execute($this->admin, [
            'customer_id' => $customer->id,
            'products' => [['product_id' => $product->id, 'quantity' => '1']],
            'payment_method' => 'cash', 'amount_paid' => $paid, 'notes' => null,
        ]);
    }

    /** @return array{0: string, 1: string} the request token and session it was bound to */
    private function settle(Sale $sale, string $amount): array
    {
        $this->actingAs($this->admin)->startSession();
        $session = app('session')->driver();
        $token = app(IssueSalePaymentRequest::class)->execute($sale, $this->admin, $session);

        app(RecordSalePayment::class)->execute($this->admin, $sale->fresh(), [
            'amount' => $amount, 'payment_method' => 'cash', 'note' => null, 'request_token' => $token,
        ], $session->getId());

        return [$token, $session->getId()];
    }

    // ── Pickup ──────────────────────────────────────────────────────────────────────────────────

    public function test_marking_ready_for_pickup_schedules_the_reminder(): void
    {
        $automation = $this->enable(WhatsAppAutomation::PICKUP_REMINDER);
        $automation->update(['delay_hours' => 24]);
        $customer = $this->customer();
        $sale = Sale::factory()->create([
            'customer_id' => $customer->id, 'is_walk_in' => false,
            'status' => SaleStatus::Completed, 'payment_status' => PaymentStatus::Paid,
        ]);

        app(MarkSaleReadyForPickup::class)->execute($this->admin, $sale);

        $sale->refresh();
        $this->assertNotNull($sale->pickup_ready_at);
        $this->assertSame($this->admin->id, $sale->pickup_ready_by);

        $message = WhatsAppMessage::query()->where('type', 'pickup_reminder')->sole();
        // Scheduled, not sent: send_after is a day past readiness.
        $this->assertNotNull($message->send_after);
        $this->assertTrue($message->send_after->greaterThan(now()->addHours(23)));
    }

    /** Marking ready twice does not reschedule or duplicate the reminder. */
    public function test_marking_ready_twice_is_idempotent(): void
    {
        $this->enable(WhatsAppAutomation::PICKUP_REMINDER);
        $customer = $this->customer();
        $sale = Sale::factory()->create([
            'customer_id' => $customer->id, 'is_walk_in' => false,
            'status' => SaleStatus::Completed, 'payment_status' => PaymentStatus::Paid,
        ]);

        app(MarkSaleReadyForPickup::class)->execute($this->admin, $sale);
        $first = $sale->refresh()->pickup_ready_at;
        app(MarkSaleReadyForPickup::class)->execute($this->admin, $sale);

        $this->assertEquals($first, $sale->refresh()->pickup_ready_at);
        $this->assertSame(1, WhatsAppMessage::query()->where('type', 'pickup_reminder')->count());
    }

    // ── Low stock ───────────────────────────────────────────────────────────────────────────────

    /**
     * The alert fires on the crossing, not on the condition, and re-arms only after recovery.
     *
     * This is the behaviour that decides whether a manager is messaged once or on every sale of an
     * already-low product.
     */
    public function test_low_stock_fires_on_crossing_and_rearms_after_recovery(): void
    {
        $this->enable(WhatsAppAutomation::LOW_STOCK);
        // The destination is the business's own alert number, not a staff recipient list.
        $this->setAlertNumber('+2348011112222');

        $product = Product::factory()->create(['reorder_level' => '8.000', 'current_stock' => '20.000']);
        $product = $this->setStock($product, '20.000');

        // Still healthy: nothing.
        $this->triggers()->stockChanged($product);
        $this->assertSame(0, WhatsAppMessage::query()->where('type', 'low_stock')->count());

        // Crosses down: exactly one alert.
        $product = $this->setStock($product, '2.000');
        $this->triggers()->stockChanged($product);
        $this->assertSame(1, WhatsAppMessage::query()->where('type', 'low_stock')->count());

        // Stays low through several more movements: still one.
        $product = $this->setStock($product, '1.000');
        $this->triggers()->stockChanged($product);
        $product = $this->setStock($product, '0.000');
        $this->triggers()->stockChanged($product);
        $this->assertSame(1, WhatsAppMessage::query()->where('type', 'low_stock')->count());

        // Restocked above the level: the episode closes and the alert re-arms.
        $product = $this->setStock($product, '30.000');
        $this->triggers()->stockChanged($product);
        $this->assertSame(1, WhatsAppMessage::query()->where('type', 'low_stock')->count());

        // A fresh crossing alerts again.
        $product = $this->setStock($product, '3.000');
        $this->triggers()->stockChanged($product);
        $this->assertSame(2, WhatsAppMessage::query()->where('type', 'low_stock')->count());
    }

    /** Only chosen, eligible staff are messaged — never a hard-coded name. */
    /**
     * The alert goes to the business's Manager alert number, and to nothing else.
     *
     * The staff-recipient pivot is deliberately populated here with a DIFFERENT number. It is the
     * retired source of truth: its rows are kept for history, and this asserts they are no longer
     * read — one message, addressed to the business's number, no second message to the pivot, and
     * no user attribution on a business-addressed alert.
     */
    public function test_low_stock_goes_to_the_business_alert_number_and_ignores_the_old_pivot(): void
    {
        $automation = $this->enable(WhatsAppAutomation::LOW_STOCK);
        $this->setAlertNumber('+2348011112222');

        $stale = User::factory()->create(['role' => UserRole::Manager, 'phone' => '+2348033334444']);
        $automation->syncRecipients([$stale->id]);

        $product = Product::factory()->create(['reorder_level' => '8.000', 'current_stock' => '2.000']);
        $product = $this->setStock($product, '2.000');
        $this->triggers()->stockChanged($product);

        $messages = WhatsAppMessage::query()->where('type', 'low_stock')->get();
        $this->assertCount(1, $messages, 'a stale pivot row must not add a second alert');
        $this->assertSame('+2348011112222', $messages->first()->destination_phone);
        $this->assertNull($messages->first()->user_id, 'a business-addressed alert has no staff owner');
        $this->assertStringNotContainsString('+2348033334444', (string) $messages->toJson());
    }

    /** No alert number means no alert — and emphatically no invented destination. */
    public function test_low_stock_without_an_alert_number_sends_nothing(): void
    {
        $this->enable(WhatsAppAutomation::LOW_STOCK);
        $this->setAlertNumber(null);

        $product = Product::factory()->create(['reorder_level' => '8.000', 'current_stock' => '2.000']);
        $product = $this->setStock($product, '2.000');
        $this->triggers()->stockChanged($product);

        $this->assertSame(0, WhatsAppMessage::query()->where('type', 'low_stock')->count());
    }

    /** A disabled automation sends nothing, however valid the alert number is. */
    public function test_a_disabled_low_stock_automation_sends_nothing(): void
    {
        $automation = $this->enable(WhatsAppAutomation::LOW_STOCK);
        $automation->forceFill(['enabled' => false])->save();
        $this->setAlertNumber('+2348011112222');

        $product = Product::factory()->create(['reorder_level' => '8.000', 'current_stock' => '2.000']);
        $product = $this->setStock($product, '2.000');
        $this->triggers()->stockChanged($product);

        $this->assertSame(0, WhatsAppMessage::query()->where('type', 'low_stock')->count());
    }

    /**
     * A failed queue write is logged structurally. The QueryException's own message carries the
     * INSERT bindings — the customer's name, number and message body — so it must not be logged.
     */
    public function test_a_queueing_failure_is_logged_without_customer_data(): void
    {
        $this->enable(WhatsAppAutomation::WELCOME);
        $customer = $this->customer();
        $customer->forceFill(['first_name' => 'Chiamaka', 'last_name' => 'Eze'])->save();
        // Too long for the column, so MySQL refuses the INSERT and reports its bindings.
        WhatsAppMessage::creating(function (WhatsAppMessage $message): void {
            $message->destination_phone .= str_repeat('9', 20);
        });
        Log::spy();

        $this->triggers()->customerCreated($customer);

        $this->assertSame(0, WhatsAppMessage::query()->count());
        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context) use ($customer): bool {
            $logged = (string) json_encode($context);

            return $context['exception'] === QueryException::class
                && $context['subject_id'] === $customer->id
                && ! array_key_exists('message', $context)
                && ! str_contains($logged, 'Chiamaka')
                && ! str_contains($logged, '8090000001');
        });
    }

    /**
     * The real path: a stock adjustment persists `current_stock`, the Product observer fires after
     * commit, and the alert carries the persisted balance.
     */
    public function test_a_stock_adjustment_across_the_reorder_level_queues_one_alert(): void
    {
        $this->enable(WhatsAppAutomation::LOW_STOCK);
        $this->setAlertNumber('+2348011112222');
        $product = Product::factory()->create(['reorder_level' => '8.000', 'current_stock' => '20.000']);
        $this->assertSame(0, WhatsAppMessage::query()->where('type', 'low_stock')->count());

        app(AdjustStock::class)->execute($this->admin, $product, [
            'type' => 'damage', 'operation' => 'decrease', 'quantity' => '15.000', 'reason' => 'Crushed in transit',
        ]);

        $message = WhatsAppMessage::query()->where('type', 'low_stock')->sole();
        $this->assertSame('5', $message->template_values['stock_left']);
        $this->assertSame('5.000', DB::table('whatsapp_low_stock_episodes')->where('product_id', $product->id)->value('stock_at_crossing'));
    }

    /** Writes the persisted balance without the observer, so each test drives the trigger itself. */
    private function setStock(Product $product, string $stock): Product
    {
        DB::table('products')->where('id', $product->id)->update(['current_stock' => $stock]);

        return $product->refresh();
    }

    /** Sets the business-level alert destination directly, as the settings form would. */
    private function setAlertNumber(?string $number): void
    {
        DB::table('business_settings')->update(['manager_alert_number' => $number]);
        app(BusinessSettings::class)->forget();
    }

    // ── Sending, status and retry ───────────────────────────────────────────────────────────────

    /** An accepted send is `sent` — never `delivered`, which only a webhook can establish. */
    public function test_an_accepted_send_is_recorded_as_sent_not_delivered(): void
    {
        $this->enable(WhatsAppAutomation::WELCOME);
        $this->triggers()->customerCreated($this->customer());

        $this->artisan('inventra:dispatch-whatsapp-messages')->assertSuccessful();

        $message = WhatsAppMessage::query()->sole();
        $this->assertSame(WhatsAppMessage::STATUS_SENT, $message->status);
        $this->assertNotNull($message->provider_message_id);
        $this->assertNull($message->delivered_at);
    }

    public function test_a_rejected_send_is_recorded_as_failed(): void
    {
        $this->enable(WhatsAppAutomation::WELCOME);
        $this->triggers()->customerCreated($this->customer());
        $this->provider->rejectSends = true;

        $this->artisan('inventra:dispatch-whatsapp-messages')->assertSuccessful();

        $this->assertSame(WhatsAppMessage::STATUS_FAILED, WhatsAppMessage::query()->sole()->status);
    }

    /** An ambiguous outcome is never marked failed: a retry could send the message twice. */
    public function test_an_unknown_outcome_is_not_marked_failed(): void
    {
        $this->enable(WhatsAppAutomation::WELCOME);
        $this->triggers()->customerCreated($this->customer());
        $this->provider->outcomeUnknown = true;

        $this->artisan('inventra:dispatch-whatsapp-messages')->assertSuccessful();

        $message = WhatsAppMessage::query()->sole();
        $this->assertSame(WhatsAppMessage::STATUS_SENT, $message->status);
        $this->assertSame('outcome_unknown', $message->failure_code);
    }

    /** The claim means a second dispatcher run cannot send the same row again. */
    public function test_a_second_dispatch_run_sends_nothing_twice(): void
    {
        $this->enable(WhatsAppAutomation::WELCOME);
        $this->triggers()->customerCreated($this->customer());

        $this->artisan('inventra:dispatch-whatsapp-messages')->assertSuccessful();
        $this->artisan('inventra:dispatch-whatsapp-messages')->assertSuccessful();

        $this->assertCount(1, $this->provider->sent);
    }

    /** A pickup reminder is not sent before its time. */
    public function test_a_scheduled_message_is_not_dispatched_early(): void
    {
        $automation = $this->enable(WhatsAppAutomation::PICKUP_REMINDER);
        $automation->update(['delay_hours' => 24]);
        $customer = $this->customer();
        $sale = Sale::factory()->create([
            'customer_id' => $customer->id, 'is_walk_in' => false,
            'status' => SaleStatus::Completed, 'payment_status' => PaymentStatus::Paid,
        ]);
        app(MarkSaleReadyForPickup::class)->execute($this->admin, $sale);

        $this->artisan('inventra:dispatch-whatsapp-messages')->assertSuccessful();
        $this->assertCount(0, $this->provider->sent);

        // Once the delay has passed it goes.
        $this->travel(25)->hours();
        $this->artisan('inventra:dispatch-whatsapp-messages')->assertSuccessful();
        $this->assertCount(1, $this->provider->sent);
    }

    /** Retry creates a new attempt and leaves the failed one untouched. */
    public function test_a_retry_preserves_the_failed_attempt(): void
    {
        $this->enable(WhatsAppAutomation::WELCOME);
        $this->triggers()->customerCreated($this->customer());
        $this->provider->rejectSends = true;
        $this->artisan('inventra:dispatch-whatsapp-messages');

        $failed = WhatsAppMessage::query()->sole();
        $this->provider->rejectSends = false;

        $retry = app(RetryWhatsAppMessage::class)->execute($this->admin, $failed);

        $this->assertSame(WhatsAppMessage::STATUS_FAILED, $failed->refresh()->status, 'history must be preserved');
        $this->assertSame(2, $retry->attempt);
        $this->assertSame($failed->id, $retry->retry_of_id);
        $this->assertSame(WhatsAppMessage::STATUS_SENT, $retry->status);
    }

    public function test_the_same_failure_cannot_be_retried_twice(): void
    {
        $this->enable(WhatsAppAutomation::WELCOME);
        $this->triggers()->customerCreated($this->customer());
        $this->provider->rejectSends = true;
        $this->artisan('inventra:dispatch-whatsapp-messages');
        $failed = WhatsAppMessage::query()->sole();
        $this->provider->rejectSends = false;

        app(RetryWhatsAppMessage::class)->execute($this->admin, $failed);

        $this->expectException(ValidationException::class);
        app(RetryWhatsAppMessage::class)->execute($this->admin, $failed->refresh());
    }

    /** Consent is re-checked at retry time, not inherited from the original attempt. */
    public function test_a_retry_respects_consent_withdrawn_since(): void
    {
        $this->enable(WhatsAppAutomation::WELCOME);
        $customer = $this->customer();
        $this->triggers()->customerCreated($customer);
        $this->provider->rejectSends = true;
        $this->artisan('inventra:dispatch-whatsapp-messages');
        $failed = WhatsAppMessage::query()->sole();

        app(SetWhatsAppConsent::class)->execute($this->admin, $customer, false);
        $this->provider->rejectSends = false;

        $this->expectException(ValidationException::class);
        app(RetryWhatsAppMessage::class)->execute($this->admin, $failed);
    }

    // ── Test messages ───────────────────────────────────────────────────────────────────────────

    /** A test goes to the Administrator's own number, and is logged as a test. */
    public function test_a_test_message_goes_only_to_the_actor(): void
    {
        $automation = $this->enable(WhatsAppAutomation::WELCOME);

        $message = app(SendWhatsAppTestMessage::class)->execute($this->admin, $automation);

        $this->assertSame('test', $message->type);
        $this->assertSame('test', $message->origin);
        $this->assertSame($this->admin->id, $message->user_id);
        $this->assertSame($this->admin->phone, $message->destination_phone);
        $this->assertNull($message->customer_id, 'a test must never reach a customer');
        // Rendered with sample values, so nothing real is disclosed.
        $this->assertStringContainsString('Emeka Obi', $message->body);
    }

    public function test_a_test_is_refused_without_a_usable_number(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin, 'phone' => null]);

        $this->expectException(ValidationException::class);
        app(SendWhatsAppTestMessage::class)->execute($admin, $this->enable(WhatsAppAutomation::WELCOME));
    }

    // ── Webhooks ────────────────────────────────────────────────────────────────────────────────

    /** Delivery is recorded only from a signed webhook, and replaying it changes nothing. */
    public function test_a_webhook_marks_delivered_and_is_replay_safe(): void
    {
        config(['whatsapp.app_secret' => 'test-secret']);
        $this->enable(WhatsAppAutomation::WELCOME);
        $this->triggers()->customerCreated($this->customer());
        $this->artisan('inventra:dispatch-whatsapp-messages');
        $message = WhatsAppMessage::query()->sole();

        $payload = json_encode(['entry' => [['id' => '100000000000001', 'changes' => [['value' => ['metadata' => ['phone_number_id' => '200000000000001'], 'statuses' => [[
            'id' => $message->provider_message_id, 'status' => 'delivered', 'timestamp' => (string) now()->timestamp,
        ]]]]]]]]);

        for ($i = 0; $i < 2; $i++) {
            $this->call('POST', route('webhooks.whatsapp.handle'), [], [], [], [
                'HTTP_X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $payload, 'test-secret'),
                'CONTENT_TYPE' => 'application/json',
            ], $payload)->assertOk();
        }

        $message->refresh();
        $this->assertSame(WhatsAppMessage::STATUS_DELIVERED, $message->status);
        $this->assertNotNull($message->delivered_at);
    }

    /** An unsigned webhook is refused, so nobody can forge a delivery. */
    public function test_an_unsigned_webhook_is_rejected(): void
    {
        config(['whatsapp.app_secret' => 'test-secret']);
        $this->enable(WhatsAppAutomation::WELCOME);
        $this->triggers()->customerCreated($this->customer());
        $this->artisan('inventra:dispatch-whatsapp-messages');
        $message = WhatsAppMessage::query()->sole();

        $payload = json_encode(['entry' => [['id' => '100000000000001', 'changes' => [['value' => ['metadata' => ['phone_number_id' => '200000000000001'], 'statuses' => [[
            'id' => $message->provider_message_id, 'status' => 'delivered',
        ]]]]]]]]);

        $this->call('POST', route('webhooks.whatsapp.handle'), [], [], [], [
            'HTTP_X-Hub-Signature-256' => 'sha256=forged',
            'CONTENT_TYPE' => 'application/json',
        ], $payload)->assertForbidden();

        $this->assertSame(WhatsAppMessage::STATUS_SENT, $message->refresh()->status);
    }

    /** A status may not move backwards, however webhooks are ordered or repeated. */
    public function test_a_status_never_regresses(): void
    {
        config(['whatsapp.app_secret' => 'test-secret']);
        $this->enable(WhatsAppAutomation::WELCOME);
        $this->triggers()->customerCreated($this->customer());
        $this->artisan('inventra:dispatch-whatsapp-messages');
        $message = WhatsAppMessage::query()->sole();

        foreach (['read', 'delivered', 'sent'] as $status) {
            $payload = json_encode(['entry' => [['id' => '100000000000001', 'changes' => [['value' => ['metadata' => ['phone_number_id' => '200000000000001'], 'statuses' => [[
                'id' => $message->provider_message_id, 'status' => $status,
            ]]]]]]]]);
            $this->call('POST', route('webhooks.whatsapp.handle'), [], [], [], [
                'HTTP_X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $payload, 'test-secret'),
                'CONTENT_TYPE' => 'application/json',
            ], $payload)->assertOk();
        }

        // `read` arrived first and outranks the rest, so it stands.
        $this->assertSame(WhatsAppMessage::STATUS_READ, $message->refresh()->status);
    }
}
