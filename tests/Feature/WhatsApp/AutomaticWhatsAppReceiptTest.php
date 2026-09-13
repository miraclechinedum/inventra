<?php

namespace Tests\Feature\WhatsApp;

use App\Actions\Sale\CreateSale;
use App\Actions\Sale\VoidSale;
use App\Actions\WhatsApp\QueueAutomaticWhatsAppReceipt;
use App\Contracts\WhatsAppClient;
use App\Enums\SaleStatus;
use App\Enums\UserRole;
use App\Enums\WhatsAppDeliveryOrigin;
use App\Enums\WhatsAppDeliveryStatus;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Models\WhatsAppDelivery;
use App\Support\WhatsAppSendResult;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fakes\FakeWhatsAppClient;
use Tests\TestCase;

/**
 * Automatic receipt delivery. The contract under test is that completing a Sale never calls Meta:
 * it only persists an eligible delivery, and the scheduler sends it afterwards. So every test here
 * asserts on two separate moments — what the sale transaction did, and what the scheduler did — and
 * on the fact that nothing about WhatsApp can reach back and disturb a recorded sale.
 */
class AutomaticWhatsAppReceiptTest extends TestCase
{
    use RefreshDatabase;

    private FakeWhatsAppClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = new FakeWhatsAppClient;
        $this->app->instance(WhatsAppClient::class, $this->client);
    }

    // ─────────────────────────────── queueing, at sale completion ───────────────────────────────

    public function test_completing_a_sale_queues_a_receipt_without_calling_the_provider(): void
    {
        $sale = $this->completeSale();

        $delivery = WhatsAppDelivery::query()->sole();
        $this->assertSame($sale->id, $delivery->sale_id);
        $this->assertSame(WhatsAppDeliveryOrigin::Automatic, $delivery->origin);
        $this->assertSame(WhatsAppDeliveryStatus::Pending, $delivery->status);
        $this->assertNull($delivery->dispatch_claimed_at, 'a freshly queued receipt must be unclaimed');
        $this->assertNull($delivery->provider_message_id);
        $this->assertSame(1, $delivery->attempt);
        $this->assertSame($sale->sold_by, $delivery->created_by);

        // The whole point of the architecture: no Meta call happened inside sale completion.
        $this->assertSame([], $this->client->requests, 'sale completion must never call the provider');
    }

    public function test_the_consent_snapshot_is_captured_when_the_receipt_is_queued(): void
    {
        $optInAt = now()->subMinutes(30)->startOfSecond();
        $sale = $this->completeSale(customerAttributes: ['whatsapp_opt_in_at' => $optInAt]);

        $delivery = WhatsAppDelivery::query()->sole();
        $customer = Customer::query()->findOrFail($sale->customer_id);
        $this->assertTrue($delivery->consent_opt_in_at_snapshot->equalTo($optInAt));
        $this->assertNotNull($delivery->consent_checked_at);
        $this->assertSame($customer->phone, $delivery->destination_phone);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function ineligibleCustomers(): array
    {
        return [
            'never opted in' => [['whatsapp_opt_in' => false, 'whatsapp_opt_in_at' => null]],
            'opted out again' => [['whatsapp_opt_out_at' => '2026-01-01 00:00:00']],
            'opted in without a timestamp' => [['whatsapp_opt_in_at' => null]],
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    #[DataProvider('ineligibleCustomers')]
    public function test_an_ineligible_customer_is_never_queued_but_the_sale_still_completes(array $attributes): void
    {
        $sale = $this->completeSale(customerAttributes: $attributes);

        $this->assertSame(SaleStatus::Completed, $sale->status);
        $this->assertDatabaseCount('whatsapp_deliveries', 0);
        $this->assertSame([], $this->client->requests);
    }

    public function test_an_inactive_customer_cannot_reach_sale_completion_at_all(): void
    {
        // Recorded here for completeness: inactive customers are refused by CreateSale itself, so
        // this branch of the eligibility rule can never be reached by automatic queueing. It still
        // matters at dispatch time, when a customer may be deactivated after the sale.
        $this->expectException(ValidationException::class);
        $this->completeSale(customerAttributes: ['is_active' => false]);
    }

    public function test_an_unconfigured_provider_leaves_the_sale_intact_and_queues_nothing(): void
    {
        $this->client->configured = false;

        $sale = $this->completeSale();

        $this->assertSame(SaleStatus::Completed, $sale->fresh()->status);
        $this->assertDatabaseCount('whatsapp_deliveries', 0);
        $this->assertSame([], $this->client->requests);
    }

    public function test_a_failure_while_queueing_cannot_disturb_the_recorded_sale(): void
    {
        // isConfigured() is consulted only after the sale has committed, so a provider client that
        // explodes there proves the sale survives an unexpected WhatsApp fault.
        $this->app->instance(WhatsAppClient::class, new class extends FakeWhatsAppClient
        {
            public function isConfigured(): bool
            {
                throw new RuntimeException('provider client exploded');
            }
        });

        $sale = $this->completeSale();

        $this->assertSame(SaleStatus::Completed, $sale->fresh()->status);
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('whatsapp_deliveries', 0);
    }

    public function test_a_sale_that_already_has_a_manual_delivery_is_not_queued_again(): void
    {
        $sale = $this->completeSale();

        // Recast the one queued attempt as a manual send a staff member got in first with.
        DB::table('whatsapp_deliveries')->update(['origin' => WhatsAppDeliveryOrigin::Manual->value]);

        // Re-running the queue action must find that attempt and decline to add another, so a
        // customer is never messaged twice for one Sale.
        $queued = app(QueueAutomaticWhatsAppReceipt::class)->execute($sale->fresh());

        $this->assertNull($queued);
        $this->assertDatabaseCount('whatsapp_deliveries', 1);
        $this->assertSame(WhatsAppDeliveryOrigin::Manual, WhatsAppDelivery::query()->sole()->origin);
    }

    // ─────────────────────────────────── scheduler dispatch ─────────────────────────────────────

    public function test_the_scheduler_sends_the_queued_receipt_and_records_the_result(): void
    {
        $this->client->result = WhatsAppSendResult::accepted('wamid.automatic-1');
        $sale = $this->completeSale();

        $this->artisanDispatch()->assertExitCode(0);

        $this->assertCount(1, $this->client->requests, 'the scheduler must call the provider exactly once');
        $delivery = WhatsAppDelivery::query()->sole();
        $this->assertSame(WhatsAppDeliveryStatus::Accepted, $delivery->status);
        $this->assertSame('wamid.automatic-1', $delivery->provider_message_id);
        $this->assertNotNull($delivery->dispatch_claimed_at, 'a dispatched row must carry its claim');
        $this->assertSame($sale->id, $delivery->sale_id);
    }

    public function test_a_second_scheduler_run_cannot_send_the_same_receipt_again(): void
    {
        $this->completeSale();

        $this->artisanDispatch();
        $this->artisanDispatch();
        $this->artisanDispatch();

        $this->assertCount(1, $this->client->requests, 'repeated scheduler runs must not duplicate a send');
        $this->assertDatabaseCount('whatsapp_deliveries', 1);
    }

    public function test_a_row_already_claimed_by_another_run_is_left_alone(): void
    {
        $this->completeSale();

        // Simulates an overlapping scheduler process that claimed the row first.
        DB::table('whatsapp_deliveries')->update(['dispatch_claimed_at' => now()]);

        $this->artisanDispatch()->expectsOutputToContain('Dispatched: 0');

        $this->assertSame([], $this->client->requests, 'a claimed row must not be sent by a second run');
        $this->assertSame(WhatsAppDeliveryStatus::Pending, WhatsAppDelivery::query()->sole()->status);
    }

    public function test_a_definitive_provider_rejection_is_recorded_and_not_retried_automatically(): void
    {
        $this->client->result = WhatsAppSendResult::rejected('template_paused', 'Template is paused.');
        $this->completeSale();

        $this->artisanDispatch();
        $this->artisanDispatch();

        $this->assertCount(1, $this->client->requests);
        $delivery = WhatsAppDelivery::query()->sole();
        $this->assertSame(WhatsAppDeliveryStatus::Failed, $delivery->status);
        $this->assertSame('template_paused', $delivery->failure_code);
    }

    public function test_an_ambiguous_provider_outcome_is_never_re_sent(): void
    {
        $this->client->outcomeUnknown = true;
        $this->completeSale();

        $this->artisanDispatch();
        $this->artisanDispatch();

        $this->assertCount(1, $this->client->requests, 'an unconfirmed outcome must never be retried by the scheduler');
        $delivery = WhatsAppDelivery::query()->sole();
        $this->assertSame('outcome_unknown', $delivery->failure_code);
        $this->assertNull($delivery->provider_message_id);
    }

    public function test_an_unconfigured_provider_makes_the_scheduler_a_no_op(): void
    {
        $this->completeSale();
        $this->client->configured = false;

        $this->artisanDispatch()->assertExitCode(0);

        $this->assertSame([], $this->client->requests);
        $this->assertNull(WhatsAppDelivery::query()->sole()->dispatch_claimed_at);
    }

    // ───────────────────────── consent re-checked at dispatch time ──────────────────────────────

    public function test_consent_withdrawn_after_queueing_stops_the_send(): void
    {
        $sale = $this->completeSale();
        Customer::query()->whereKey($sale->customer_id)->update([
            'whatsapp_opt_in' => false,
            'whatsapp_opt_out_at' => now(),
        ]);

        $this->artisanDispatch();

        $this->assertSame([], $this->client->requests, 'a customer who opted out must not be messaged');
        $delivery = WhatsAppDelivery::query()->sole();
        $this->assertSame(WhatsAppDeliveryStatus::Failed, $delivery->status);
        $this->assertSame('consent_withdrawn', $delivery->failure_code);
    }

    public function test_a_sale_voided_before_dispatch_stops_the_send(): void
    {
        $sale = $this->completeSale();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        app(VoidSale::class)->execute($admin, $sale->fresh(), 'Voided before the receipt went out.');

        $this->artisanDispatch();

        $this->assertSame([], $this->client->requests);
        $this->assertSame('sale_no_longer_completed', WhatsAppDelivery::query()->sole()->failure_code);
    }

    // ─────────────────────── manual sending stays separate and available ────────────────────────

    public function test_the_dispatcher_never_claims_a_manual_delivery(): void
    {
        $seller = User::factory()->create(['role' => UserRole::SalesRep]);
        $sale = $this->completeSale(seller: $seller);

        // Recast the queued row as a manual send sitting momentarily at pending — exactly the state
        // a manual send occupies between its own commit and the provider's reply.
        DB::table('whatsapp_deliveries')->update([
            'origin' => WhatsAppDeliveryOrigin::Manual->value,
            'dispatch_claimed_at' => null,
        ]);

        $this->artisanDispatch()->expectsOutputToContain('Dispatched: 0');

        $this->assertSame([], $this->client->requests, 'a pending manual send must be invisible to the scheduler');
        $delivery = WhatsAppDelivery::query()->sole();
        $this->assertSame(WhatsAppDeliveryStatus::Pending, $delivery->status);
        $this->assertNull($delivery->dispatch_claimed_at);
    }

    public function test_the_database_refuses_to_claim_a_manual_delivery(): void
    {
        $this->completeSale();
        DB::table('whatsapp_deliveries')->update(['origin' => WhatsAppDeliveryOrigin::Manual->value]);

        $this->expectException(QueryException::class);
        DB::table('whatsapp_deliveries')->update(['dispatch_claimed_at' => now()]);
    }

    public function test_manual_send_and_retry_still_work_alongside_automatic_delivery(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $sale = $this->completeSale();

        // The automatic attempt fails, then a staff member sends manually.
        $this->client->result = WhatsAppSendResult::rejected('rate_limited', 'Too many messages.');
        $this->artisanDispatch();
        $automatic = WhatsAppDelivery::query()->sole();
        $this->assertSame(WhatsAppDeliveryOrigin::Automatic, $automatic->origin);
        $this->assertSame(WhatsAppDeliveryStatus::Failed, $automatic->status);

        $this->client->result = WhatsAppSendResult::accepted('wamid.manual-1');
        $token = (string) Str::uuid();
        $this->actingAs($admin)->withSession(['whatsapp.send.'.$sale->id => $token])
            ->post(route('sales.whatsapp.send', $sale), ['request_token' => $token])
            ->assertRedirect(route('sales.show', $sale));

        $manual = WhatsAppDelivery::query()->where('origin', WhatsAppDeliveryOrigin::Manual->value)->sole();
        $this->assertSame(WhatsAppDeliveryStatus::Accepted, $manual->status);
        $this->assertSame(2, $manual->attempt, 'a manual send after an automatic one is the next attempt');
        $this->assertSame($admin->id, $manual->created_by);

        // And a retry of the failed automatic attempt is still offered to staff.
        $this->client->result = WhatsAppSendResult::accepted('wamid.retry-1');
        $retryToken = (string) Str::uuid();
        $this->actingAs($admin)->withSession(['whatsapp.retry.'.$automatic->id => $retryToken])
            ->post(route('sales.whatsapp.retry', [$sale, $automatic]), ['request_token' => $retryToken])
            ->assertRedirect(route('whatsapp.deliveries.show', $automatic));

        $this->assertSame(3, WhatsAppDelivery::query()->count());
        $this->assertSame(
            2,
            WhatsAppDelivery::query()->where('origin', WhatsAppDeliveryOrigin::Manual->value)->count(),
            'a staff retry is a manual attempt, never an automatic one',
        );
    }

    // ─────────────────────────────────────── audit trail ────────────────────────────────────────

    public function test_the_audit_trail_separates_system_dispatch_from_staff_sending(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $sale = $this->completeSale();

        $queued = AuditLog::query()->where('action', 'whatsapp_receipt_auto_queued')->sole();
        $this->assertNull($queued->actor_id, 'queueing is a system action, not the seller\'s');
        $this->assertSame('System', $queued->actor_name_snapshot);
        $this->assertSame('automatic', $queued->metadata['origin'],
            'the trail must distinguish an automatic receipt from a staff-sent one');

        $this->artisanDispatch();
        $accepted = AuditLog::query()->where('action', 'whatsapp_receipt_accepted')->sole();
        $this->assertNull($accepted->actor_id, 'automatic dispatch is attributed to the system');

        // A staff send, by contrast, names the person.
        $token = (string) Str::uuid();
        $this->actingAs($admin)->withSession(['whatsapp.send.'.$sale->id => $token])
            ->post(route('sales.whatsapp.send', $sale), ['request_token' => $token]);

        $requested = AuditLog::query()->where('action', 'whatsapp_receipt_requested')->sole();
        $this->assertSame($admin->id, $requested->actor_id);
        $this->assertSame($admin->name, $requested->actor_name_snapshot);
    }

    public function test_an_abandoned_dispatch_is_audited_with_its_reason(): void
    {
        $sale = $this->completeSale();
        Customer::query()->whereKey($sale->customer_id)->update(['is_active' => false]);

        $this->artisanDispatch();

        $log = AuditLog::query()->where('action', 'whatsapp_receipt_auto_abandoned')->sole();
        $this->assertNull($log->actor_id);
        $this->assertSame('consent_withdrawn', $log->metadata['reason']);
    }

    // ────────────────────────────────────── discoverability ─────────────────────────────────────

    public function test_the_delivery_history_shows_whether_an_attempt_was_automatic(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $sale = $this->completeSale();
        $this->artisanDispatch();

        $index = $this->actingAs($admin)->get(route('whatsapp.deliveries.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Origin', $index);
        $this->assertStringContainsString('Automatic', $index);

        $show = $this->actingAs($admin)->get(route('whatsapp.deliveries.show', WhatsAppDelivery::query()->sole()))
            ->assertOk()->getContent();
        $this->assertStringContainsString('Automatic', $show);
        $this->assertStringContainsString('Dispatched by scheduler', $show);

        $salePage = $this->actingAs($admin)->get(route('sales.show', $sale))->assertOk()->getContent();
        $this->assertStringContainsString('queued automatically', $salePage,
            'staff must be told the receipt goes out on its own');
    }

    public function test_the_sign_in_page_no_longer_promises_whatsapp_follow_ups(): void
    {
        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertStringNotContainsString('follow-ups', $html);
        $this->assertStringContainsString('Automatic WhatsApp receipts', $html);
    }

    // ───────────────────────────────────────── fixtures ─────────────────────────────────────────

    /**
     * Records a real Sale through CreateSale, which is what triggers automatic queueing.
     *
     * @param  array<string, mixed>  $customerAttributes
     */
    private function completeSale(array $customerAttributes = [], ?User $seller = null): Sale
    {
        $seller ??= User::factory()->create(['role' => UserRole::SalesRep]);
        $customer = Customer::factory()->create(array_merge([
            'is_active' => true,
            'whatsapp_opt_in' => true,
            'whatsapp_opt_in_at' => now()->subMinute(),
            'whatsapp_opt_out_at' => null,
        ], $customerAttributes));
        $product = Product::factory()->create(['current_stock' => '10.000', 'selling_price' => '2500.00']);

        return app(CreateSale::class)->execute($seller, [
            'customer_id' => $customer->id,
            'products' => [['product_id' => $product->id, 'quantity' => '2']],
            'payment_method' => 'cash',
            'amount_paid' => '5000.00',
            'notes' => null,
        ]);
    }

    private function artisanDispatch()
    {
        return $this->artisan('inventra:dispatch-whatsapp-receipts');
    }
}
