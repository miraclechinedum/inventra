<?php

namespace Tests\Feature\WhatsApp;

use App\Actions\Customer\SetWhatsAppConsent;
use App\Actions\Customer\UpdateCustomer;
use App\Actions\WhatsApp\SendWhatsAppReceipt;
use App\Actions\WhatsApp\TransitionWhatsAppDelivery;
use App\Contracts\WhatsAppClient;
use App\Enums\PaymentStatus;
use App\Enums\SaleStatus;
use App\Enums\UserRole;
use App\Enums\WhatsAppDeliveryStatus;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\User;
use App\Models\WhatsAppDelivery;
use App\Support\WhatsAppSendResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Fakes\FakeWhatsAppClient;
use Tests\TestCase;

class WhatsAppDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private FakeWhatsAppClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = new FakeWhatsAppClient;
        $this->app->instance(WhatsAppClient::class, $this->client);
    }

    public function test_roles_enforce_send_and_log_boundaries(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $salesRep = User::factory()->create(['role' => UserRole::SalesRep]);
        $otherRep = User::factory()->create(['role' => UserRole::SalesRep]);
        $sale = $this->sale($salesRep);

        $this->get(route('whatsapp.deliveries.index'))->assertRedirect(route('login'));
        $this->actingAs($admin)->get(route('whatsapp.deliveries.index'))->assertOk();
        $this->actingAs($manager)->get(route('whatsapp.deliveries.index'))->assertOk();
        $this->actingAs($salesRep)->get(route('whatsapp.deliveries.index'))->assertForbidden();
        $this->postSend($salesRep, $sale)->assertRedirect(route('sales.show', $sale));
        $this->postSend($otherRep, $sale)->assertForbidden();
        $delivery = WhatsAppDelivery::query()->sole();
        $this->actingAs($salesRep)->get(route('whatsapp.deliveries.show', $delivery))->assertOk();
        $this->actingAs($otherRep)->get(route('whatsapp.deliveries.show', $delivery))->assertForbidden();
    }

    public function test_locked_live_customer_controls_eligibility_and_destination(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = mb_strtolower($query->sql);
        });
        $seller = User::factory()->create(['role' => UserRole::SalesRep]);
        $sale = $this->sale($seller, '+2348011111111');
        DB::table('customers')->where('id', $sale->customer_id)->update([
            'phone' => '+2348099999999',
            'whatsapp_opt_in' => true,
            'whatsapp_opt_in_at' => now(),
            'whatsapp_opt_out_at' => null,
        ]);

        $delivery = app(SendWhatsAppReceipt::class)->execute($seller, $sale, (string) Str::uuid());

        $this->assertSame('+2348099999999', $delivery->destination_phone);
        $this->assertSame('+2348011111111', $sale->customer_phone_snapshot);
        $this->assertSame($sale->customer_name_snapshot, $this->client->requests[0]['parameters']['customer_name']);
        $this->assertSame($sale->sold_by_name_snapshot, $this->client->requests[0]['parameters']['sold_by_name']);
        $this->assertNotNull(collect($queries)->first(fn (string $sql): bool => str_contains($sql, 'from `customers`') && str_contains($sql, 'for update')));

        DB::table('customers')->where('id', $sale->customer_id)->update(['phone' => '+2348077777777']);
        $this->assertSame('+2348099999999', $delivery->fresh()->destination_phone);
    }

    public function test_inactive_opted_out_never_consented_and_reset_consent_are_rejected(): void
    {
        $seller = User::factory()->create(['role' => UserRole::SalesRep]);

        foreach ([
            ['is_active' => false, 'whatsapp_opt_in' => true, 'whatsapp_opt_in_at' => now(), 'whatsapp_opt_out_at' => null],
            ['is_active' => true, 'whatsapp_opt_in' => false, 'whatsapp_opt_in_at' => null, 'whatsapp_opt_out_at' => now()],
            ['is_active' => true, 'whatsapp_opt_in' => false, 'whatsapp_opt_in_at' => null, 'whatsapp_opt_out_at' => null],
            ['is_active' => true, 'whatsapp_opt_in' => true, 'whatsapp_opt_in_at' => null, 'whatsapp_opt_out_at' => null],
        ] as $state) {
            $customer = Customer::factory()->create($state);
            $sale = Sale::factory()->create(['customer_id' => $customer, 'sold_by' => $seller]);

            try {
                app(SendWhatsAppReceipt::class)->execute($seller, $sale, (string) Str::uuid());
                $this->fail('Ineligible consent state should fail.');
            } catch (ValidationException) {
                $this->assertDatabaseMissing('whatsapp_deliveries', ['sale_id' => $sale->id]);
            }
        }
    }

    public function test_idempotent_submission_provider_outcomes_and_retry_history(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $sale = $this->sale($admin);
        $token = (string) Str::uuid();
        $action = app(SendWhatsAppReceipt::class);
        $first = $action->execute($admin, $sale, $token);
        $replay = $action->execute($admin, $sale, $token);
        $this->assertSame($first->id, $replay->id);
        $this->assertCount(1, $this->client->requests);
        $this->assertSame(WhatsAppDeliveryStatus::Accepted, $first->status);
        $this->assertSame('wamid.test-message', $first->provider_message_id);

        $this->client->result = WhatsAppSendResult::rejected('131000', 'Template rejected.');
        $failed = $action->execute($admin, $sale, (string) Str::uuid());
        $this->assertSame(WhatsAppDeliveryStatus::Failed, $failed->status);
        $this->client->result = WhatsAppSendResult::accepted('wamid.retry-message');
        $retry = $action->execute($admin, $sale, (string) Str::uuid(), $failed);
        $this->assertSame(3, $retry->attempt);
        $this->assertSame(WhatsAppDeliveryStatus::Failed, $failed->fresh()->status);
        $this->assertDatabaseCount('whatsapp_deliveries', 3);
    }

    public function test_timeout_malformed_outcome_and_unconfigured_provider_fail_safely(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $sale = $this->sale($admin);
        $this->client->outcomeUnknown = true;
        $delivery = app(SendWhatsAppReceipt::class)->execute($admin, $sale, (string) Str::uuid());
        $this->assertSame(WhatsAppDeliveryStatus::Pending, $delivery->status);
        $this->assertSame('outcome_unknown', $delivery->failure_code);

        $this->client->outcomeUnknown = false;
        $this->client->configured = false;

        try {
            app(SendWhatsAppReceipt::class)->execute($admin, $sale, (string) Str::uuid());
            $this->fail('Unconfigured provider should fail safely.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('whatsapp', $exception->errors());
        }
    }

    public function test_duplicate_provider_message_id_requires_reconciliation_without_a_server_error(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $action = app(SendWhatsAppReceipt::class);
        $first = $action->execute($admin, $this->sale($admin), (string) Str::uuid());
        $second = $action->execute($admin, $this->sale($admin), (string) Str::uuid());

        $this->assertSame(WhatsAppDeliveryStatus::Accepted, $first->status);
        $this->assertSame(WhatsAppDeliveryStatus::Pending, $second->status);
        $this->assertSame('outcome_unknown', $second->failure_code);
        $this->assertNull($second->provider_message_id);
    }

    public function test_voided_sale_and_route_tampering_are_rejected(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $sale = $this->sale($admin);
        DB::table('sales')->where('id', $sale->id)->update(['status' => SaleStatus::Voided->value]);
        $sale->refresh();
        $this->postSend($admin, $sale)->assertForbidden();

        $validSale = $this->sale($admin);
        $otherSale = $this->sale($admin);
        $this->client->result = WhatsAppSendResult::rejected('rejected', 'Rejected');
        $failed = app(SendWhatsAppReceipt::class)->execute($admin, $validSale, (string) Str::uuid());
        $token = (string) Str::uuid();
        $this->actingAs($admin)->withSession(['whatsapp.retry.'.$failed->id => $token])
            ->post(route('sales.whatsapp.retry', [$otherSale, $failed]), ['request_token' => $token])
            ->assertNotFound();
    }

    public function test_ambiguous_attempt_is_admin_resolved_without_becoming_retryable(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $salesRep = User::factory()->create(['role' => UserRole::SalesRep]);
        $sale = $this->sale($salesRep);
        $this->client->outcomeUnknown = true;
        $delivery = app(SendWhatsAppReceipt::class)->execute($salesRep, $sale, (string) Str::uuid());
        $original = $delivery->only([
            'request_id', 'destination_phone', 'consent_checked_at', 'requested_at', 'failure_code', 'failure_reason',
        ]);
        $original['consent_checked_at'] = $delivery->consent_checked_at->toISOString();
        $original['requested_at'] = $delivery->requested_at->toISOString();
        $route = route('whatsapp.deliveries.resolve-unknown', $delivery);

        $this->actingAs($manager)->post($route, ['resolution_note' => 'Checked manually without a conclusive result.'])->assertForbidden();
        $this->actingAs($salesRep)->post($route, ['resolution_note' => 'Checked manually without a conclusive result.'])->assertForbidden();
        $this->actingAs($admin)->post($route, [
            'resolution_note' => 'Checked manually in Meta WhatsApp Manager; delivery outcome could not be confirmed.',
        ])->assertRedirect(route('whatsapp.deliveries.show', $delivery));

        $delivery->refresh();
        $this->assertSame(WhatsAppDeliveryStatus::Unresolved, $delivery->status);
        $this->assertSame($admin->id, $delivery->resolved_by);
        $this->assertNotNull($delivery->resolved_at);
        $preserved = $delivery->only(array_keys($original));
        $preserved['consent_checked_at'] = $delivery->consent_checked_at->toISOString();
        $preserved['requested_at'] = $delivery->requested_at->toISOString();
        $this->assertSame($original, $preserved);
        $this->assertNull($delivery->provider_message_id);
        $this->assertFalse($admin->can('retry', $delivery));
        $retryToken = (string) Str::uuid();
        $this->actingAs($admin)->withSession(['whatsapp.retry.'.$delivery->id => $retryToken])
            ->post(route('sales.whatsapp.retry', [$sale, $delivery]), ['request_token' => $retryToken])
            ->assertForbidden();
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'whatsapp_delivery_marked_unresolved',
            'auditable_id' => $delivery->id,
            'actor_id' => $admin->id,
        ]);
    }

    public function test_request_ids_cannot_be_replayed_across_sales_or_users(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $firstSale = $this->sale($admin);
        $secondSale = $this->sale($admin);
        $token = (string) Str::uuid();
        $action = app(SendWhatsAppReceipt::class);
        $action->execute($admin, $firstSale, $token);

        foreach ([[$admin, $secondSale], [$manager, $firstSale]] as [$actor, $sale]) {
            try {
                $action->execute($actor, $sale, $token);
                $this->fail('A request ID must not cross a Sale or actor boundary.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('request_token', $exception->errors());
            }
        }

        $crossSaleToken = (string) Str::uuid();
        $this->actingAs($admin)->withSession(['whatsapp.send.'.$firstSale->id => $crossSaleToken])
            ->post(route('sales.whatsapp.send', $secondSale), ['request_token' => $crossSaleToken])
            ->assertStatus(422)
            ->assertSee('This send confirmation has expired');
    }

    public function test_global_list_masks_destination_while_authorized_detail_shows_it(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $delivery = app(SendWhatsAppReceipt::class)->execute(
            $admin,
            $this->sale($admin, '+2348012345678'),
            (string) Str::uuid(),
        );

        $this->actingAs($admin)->get(route('whatsapp.deliveries.index'))
            ->assertOk()
            ->assertSee('+234801***5678')
            ->assertDontSee($delivery->destination_phone);
        $this->get(route('whatsapp.deliveries.show', $delivery))
            ->assertOk()
            ->assertSee($delivery->destination_phone);
    }

    public function test_provider_failure_logs_only_safe_delivery_context(): void
    {
        Log::spy();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $sale = $this->sale($admin);
        $this->client->result = WhatsAppSendResult::rejected('provider_rejected', 'secret response content');
        app(SendWhatsAppReceipt::class)->execute($admin, $sale, (string) Str::uuid());

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context) use ($sale): bool {
            return $message === 'WhatsApp provider definitively rejected a receipt delivery.'
                && $context['sale_id'] === $sale->id
                && array_keys($context) === ['delivery_id', 'sale_id', 'failure_code']
                && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'secret response content');
        });
    }

    public function test_resolution_note_boundaries_and_nested_values_fail_safely(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        foreach (['', str_repeat('a', 9), str_repeat('a', 501), ['nested'], ['value' => ['nested']]] as $invalid) {
            $delivery = $this->ambiguousDelivery($admin);
            $this->actingAs($admin)->post(route('whatsapp.deliveries.resolve-unknown', $delivery), [
                'resolution_note' => $invalid,
            ])->assertSessionHasErrors('resolution_note');
            $this->assertSame(WhatsAppDeliveryStatus::Pending, $delivery->fresh()->status);
        }

        foreach ([str_repeat('a', 10), str_repeat('a', 500)] as $valid) {
            $delivery = $this->ambiguousDelivery($admin);
            $this->actingAs($admin)->post(route('whatsapp.deliveries.resolve-unknown', $delivery), [
                'resolution_note' => $valid,
            ])->assertRedirect(route('whatsapp.deliveries.show', $delivery));
            $this->assertSame($valid, $delivery->fresh()->resolution_note);
        }
    }

    public function test_resolution_note_is_escaped_on_delivery_detail(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $delivery = $this->ambiguousDelivery($admin);
        $payload = '<script>alert(1)</script>';
        $this->actingAs($admin)->post(route('whatsapp.deliveries.resolve-unknown', $delivery), [
            'resolution_note' => $payload,
        ])->assertRedirect();

        $this->get(route('whatsapp.deliveries.show', $delivery))
            ->assertOk()
            ->assertDontSee($payload, false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    public function test_admin_cannot_resolve_any_delivery_outside_the_exact_ambiguous_state(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $transition = app(TransitionWhatsAppDelivery::class);
        $states = [];

        foreach ([WhatsAppDeliveryStatus::Accepted, WhatsAppDeliveryStatus::Sent, WhatsAppDeliveryStatus::Delivered, WhatsAppDeliveryStatus::Read] as $status) {
            $delivery = $this->acceptedDelivery($admin);

            if ($status !== WhatsAppDeliveryStatus::Accepted) {
                $delivery = $transition->execute($delivery, $status);
            }

            $states[] = $delivery;
        }

        $this->client->result = WhatsAppSendResult::rejected('provider_rejected', 'Definitive failure.');
        $states[] = app(SendWhatsAppReceipt::class)->execute($admin, $this->sale($admin), (string) Str::uuid());
        $normalPending = $this->ambiguousDelivery($admin);
        DB::table('whatsapp_deliveries')->where('id', $normalPending->id)->update([
            'failure_code' => null,
            'failure_reason' => null,
        ]);
        $states[] = $normalPending->fresh();
        $identifiedPending = $this->ambiguousDelivery($admin);
        DB::table('whatsapp_deliveries')->where('id', $identifiedPending->id)->update([
            'provider_message_id' => 'wamid.identified-pending',
        ]);
        $states[] = $identifiedPending->fresh();
        $unresolved = $this->ambiguousDelivery($admin);
        $this->actingAs($admin)->post(route('whatsapp.deliveries.resolve-unknown', $unresolved), [
            'resolution_note' => 'Manual investigation was inconclusive.',
        ])->assertRedirect();
        $states[] = $unresolved->fresh();

        foreach ($states as $delivery) {
            $beforeStatus = $delivery->status;
            $this->actingAs($admin)->post(route('whatsapp.deliveries.resolve-unknown', $delivery), [
                'resolution_note' => 'This transition must remain forbidden.',
            ])->assertForbidden();
            $this->assertSame($beforeStatus, $delivery->fresh()->status);
        }

        $guestDelivery = $this->ambiguousDelivery($admin);
        auth()->logout();
        $this->post(route('whatsapp.deliveries.resolve-unknown', $guestDelivery), [
            'resolution_note' => 'Guest resolution must be rejected.',
        ])->assertRedirect(route('login'));
    }

    public function test_definitive_retry_captures_fresh_live_phone_and_consent_evidence(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $sale = $this->sale($admin, '+2348011111111');
        $this->client->result = WhatsAppSendResult::rejected('provider_rejected', 'Definitive failure.');
        $first = app(SendWhatsAppReceipt::class)->execute($admin, $sale, (string) Str::uuid());
        $firstEvidence = [
            'destination_phone' => $first->destination_phone,
            'consent_checked_at' => $first->consent_checked_at->toISOString(),
            'consent_opt_in_at_snapshot' => $first->consent_opt_in_at_snapshot->toISOString(),
        ];

        $customer = $sale->customer;
        app(UpdateCustomer::class)->execute($admin, $customer, ['phone' => '+2348099999999']);
        $customer = app(SetWhatsAppConsent::class)->execute($admin, $customer->fresh(), true);
        $this->client->result = WhatsAppSendResult::accepted('wamid.fresh-retry');
        $second = app(SendWhatsAppReceipt::class)->execute($admin, $sale, (string) Str::uuid(), $first);

        $this->assertSame(1, $first->attempt);
        $this->assertSame(2, $second->attempt);
        $this->assertSame('+2348011111111', $first->fresh()->destination_phone);
        $first->refresh();
        $this->assertSame($firstEvidence, [
            'destination_phone' => $first->destination_phone,
            'consent_checked_at' => $first->consent_checked_at->toISOString(),
            'consent_opt_in_at_snapshot' => $first->consent_opt_in_at_snapshot->toISOString(),
        ]);
        $this->assertSame('+2348099999999', $second->destination_phone);
        $this->assertTrue($second->consent_checked_at->greaterThanOrEqualTo($first->consent_checked_at));
        $this->assertTrue($second->consent_opt_in_at_snapshot->equalTo($customer->whatsapp_opt_in_at));
    }

    private function sale(User $seller, ?string $phone = null): Sale
    {
        $customer = Customer::factory()->create(array_filter([
            'phone' => $phone,
            'whatsapp_opt_in' => true,
            'whatsapp_opt_in_at' => now()->subMinute(),
            'whatsapp_opt_out_at' => null,
        ], fn ($value): bool => $value !== null));

        return Sale::factory()->create([
            'customer_id' => $customer,
            'sold_by' => $seller,
            'payment_status' => PaymentStatus::Paid,
        ]);
    }

    private function ambiguousDelivery(User $actor): WhatsAppDelivery
    {
        $this->client->outcomeUnknown = true;
        $delivery = app(SendWhatsAppReceipt::class)->execute($actor, $this->sale($actor), (string) Str::uuid());
        $this->client->outcomeUnknown = false;

        return $delivery;
    }

    private function acceptedDelivery(User $actor): WhatsAppDelivery
    {
        $this->client->result = WhatsAppSendResult::accepted('wamid.'.Str::uuid());

        return app(SendWhatsAppReceipt::class)->execute($actor, $this->sale($actor), (string) Str::uuid());
    }

    private function postSend(User $actor, Sale $sale)
    {
        $token = (string) Str::uuid();

        return $this->actingAs($actor)->withSession(['whatsapp.send.'.$sale->id => $token])
            ->post(route('sales.whatsapp.send', $sale), ['request_token' => $token]);
    }
}
