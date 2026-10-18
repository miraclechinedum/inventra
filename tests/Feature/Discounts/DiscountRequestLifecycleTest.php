<?php

namespace Tests\Feature\Discounts;

use App\Actions\Sale\CancelSaleDiscount;
use App\Actions\Sale\DecideSaleDiscount;
use App\Actions\Sale\RequestSaleDiscount;
use App\Enums\DiscountRequestStatus;
use App\Enums\PaymentStatus;
use App\Enums\SaleStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleDiscountRequest;
use App\Models\SaleDraft;
use App\Models\User;
use App\Subscriptions\Entitlements;
use App\Tenancy\CurrentBusiness;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SubmitsSales;
use Tests\TestCase;

/**
 * Cancellation, expiry and the sole-Administrator exception.
 *
 * A pending request can be withdrawn by whoever asked or by an Administrator, expires after 24
 * hours, and a pre-sale approval lasts until the end of the business day it was granted. Expiry is
 * enforced from the requests' own timestamps wherever they are used; the scheduler only records it.
 */
class DiscountRequestLifecycleTest extends TestCase
{
    use RefreshDatabase, SubmitsSales;

    private User $admin;

    private User $rep;

    private Customer $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame(
            'inventra_test',
            DB::connection()->getDatabaseName(),
            'Refusing to run: these tests write, and this is not the test database.'
        );

        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->rep = User::factory()->create(['role' => UserRole::SalesRep]);
        $this->customer = Customer::factory()->create(['is_active' => true]);
        $this->product = Product::factory()->create(['selling_price' => '1000.00', 'current_stock' => '20.000']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function freeze(string $utc): void
    {
        Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse($utc, 'UTC'));
    }

    private function today(): string
    {
        return CarbonImmutable::now(config('business.timezone'))->toDateString();
    }

    private function cart(): array
    {
        return [['product_id' => $this->product->id, 'quantity' => '2']];
    }

    /** A pre-sale request on the default cart, raised over HTTP as Record Sale raises it. */
    private function draftRequest(?User $as = null, ?string $saleDate = null): SaleDiscountRequest
    {
        $response = $this->actingAs($as ?? $this->rep)->postJson(route('sales.discounts.draft.store'), [
            'amount' => '300', 'reason' => 'Regular fleet customer discount', 'is_walk_in' => false,
            'customer_id' => $this->customer->id, 'sale_date' => $saleDate ?? $this->today(), 'products' => $this->cart(),
        ])->assertCreated();

        return SaleDiscountRequest::query()->findOrFail($response->json('discount_request_id'));
    }

    private function salePayload(SaleDiscountRequest $request, ?string $saleDate = null): array
    {
        return [
            'is_walk_in' => '0', 'customer_id' => $this->customer->id, 'sale_date' => $saleDate ?? $this->today(),
            'products' => $this->cart(), 'amount_paid' => '0.00', 'sale_draft_id' => $request->sale_draft_id,
        ];
    }

    private function completedSale(?User $seller = null): Sale
    {
        return Sale::factory()->create([
            'customer_id' => $this->customer->id, 'sold_by' => ($seller ?? $this->rep)->id,
            'status' => SaleStatus::Completed, 'subtotal' => '10000.00', 'discount_amount' => '0.00',
            'total_amount' => '10000.00', 'amount_paid' => '0.00', 'balance_due' => '10000.00',
            'payment_status' => PaymentStatus::Unpaid,
        ]);
    }

    // ── Cancellation ────────────────────────────────────────────────────────────────────────────

    public function test_the_requester_cancels_their_pending_request_and_it_can_no_longer_be_spent(): void
    {
        $request = $this->draftRequest();

        $this->actingAs($this->rep)->postJson(route('discounts.cancel', $request))
            ->assertOk()->assertJsonPath('status', 'cancelled');

        $request->refresh();
        $this->assertSame(DiscountRequestStatus::Cancelled, $request->status);
        $this->assertSame($this->rep->id, (int) $request->decided_by);
        $this->assertNull($request->pending_draft_guard, 'the cart is released');
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'sale_discount_cancelled')->where('auditable_id', $request->id)->where('actor_id', $this->rep->id)->count());

        $this->actingAs($this->rep)->post(route('sales.store'), $this->withSaleToken($this->salePayload($request)))
            ->assertSessionHasErrors(['products' => 'That discount request was cancelled.']);
        $this->assertSame(0, Sale::query()->count());
    }

    public function test_an_administrator_may_cancel_anyones_request_and_no_one_else_may(): void
    {
        $sale = $this->completedSale();
        $request = app(RequestSaleDiscount::class)->execute($this->rep, $sale, ['amount' => '500.00', 'reason' => 'Goodwill on a damaged carton.']);

        foreach ([User::factory()->create(['role' => UserRole::Manager]), User::factory()->create(['role' => UserRole::SalesRep])] as $other) {
            $this->actingAs($other)->post(route('discounts.cancel', $request))->assertForbidden();
        }
        $this->assertSame(DiscountRequestStatus::Pending, $request->fresh()->status);

        $this->actingAs($this->admin)->post(route('discounts.cancel', $request))
            ->assertRedirect(route('discounts.show', $request));
        $this->assertSame(DiscountRequestStatus::Cancelled, $request->fresh()->status);
        $this->assertSame('0.00', $sale->fresh()->discount_amount, 'cancelling moves no money');

        // The Sale is free for a new request.
        app(RequestSaleDiscount::class)->execute($this->rep, $sale->fresh(), ['amount' => '400.00', 'reason' => 'Asked again with a smaller amount.']);
        $this->assertSame(2, $sale->discountRequests()->count());
    }

    public function test_another_business_cannot_see_cancel_or_decide_a_request(): void
    {
        $request = $this->draftRequest();
        $outsider = User::factory()->forBusiness(Business::factory()->create())->create(['role' => UserRole::Admin]);

        $this->actingAs($outsider)->get(route('discounts.show', $request))->assertNotFound();
        $this->actingAs($outsider)->post(route('discounts.cancel', $request))->assertNotFound();
        $this->actingAs($outsider)->post(route('discounts.approve', $request))->assertNotFound();
        $this->actingAs($outsider)->getJson(route('sales.discounts.draft.status', $request))->assertNotFound();

        $this->assertSame(DiscountRequestStatus::Pending, $request->fresh()->status);
    }

    public function test_a_cancelled_request_cannot_be_decided_and_a_decided_one_cannot_be_cancelled(): void
    {
        $cancelled = $this->draftRequest();
        app(CancelSaleDiscount::class)->execute($this->rep, $cancelled);

        try {
            app(DecideSaleDiscount::class)->approve($this->admin, $cancelled->fresh());
            $this->fail('A cancelled request must not be approved.');
        } catch (ValidationException $exception) {
            $this->assertSame(['This discount request has already been Cancelled.'], $exception->errors()['request']);
        }

        $approved = $this->draftRequest();
        app(DecideSaleDiscount::class)->approve($this->admin, $approved);
        $this->actingAs($this->rep)->post(route('discounts.cancel', $approved))->assertForbidden();

        $this->expectException(ValidationException::class);
        app(CancelSaleDiscount::class)->execute($this->rep, $approved->fresh());
    }

    // ── Expiry of pending requests ──────────────────────────────────────────────────────────────

    public function test_a_pending_request_expires_after_24_hours_and_can_no_longer_be_acted_on(): void
    {
        $request = $this->draftRequest();

        $this->travel(SaleDiscountRequest::PENDING_LIFETIME_HOURS)->hours();
        $this->travel(1)->minutes();

        $this->assertTrue($request->fresh()->isPendingExpired());
        $this->assertFalse($this->admin->can('approve', $request->fresh()));
        $this->assertFalse($this->admin->can('decline', $request->fresh()));
        $this->assertFalse($this->rep->can('cancel', $request->fresh()));
        $this->actingAs($this->rep)->getJson(route('sales.discounts.draft.status', $request))->assertJsonPath('status', 'expired');
        $this->assertSame(0, SaleDiscountRequest::query()->awaitingDecision()->count());

        foreach (['approve' => fn () => app(DecideSaleDiscount::class)->approve($this->admin, $request->fresh()),
            'decline' => fn () => app(DecideSaleDiscount::class)->decline($this->admin, $request->fresh(), 'Too late.')] as $decision => $attempt) {
            try {
                $attempt();
                $this->fail("An expired request must not be {$decision}d.");
            } catch (ValidationException $exception) {
                $this->assertStringContainsString('can no longer be decided', $exception->errors()['request'][0]);
            }
        }

        $this->assertSame(DiscountRequestStatus::Pending, $request->fresh()->status, 'nothing was recorded by the refusals');
    }

    public function test_a_stale_request_does_not_block_a_new_one_on_the_same_sale(): void
    {
        $sale = $this->completedSale();
        $stale = app(RequestSaleDiscount::class)->execute($this->rep, $sale, ['amount' => '500.00', 'reason' => 'Goodwill on a damaged carton.']);
        $this->travel(25)->hours();

        $fresh = app(RequestSaleDiscount::class)->execute($this->rep, $sale->fresh(), ['amount' => '400.00', 'reason' => 'Raised again after the first lapsed.']);

        $stale->refresh();
        $this->assertSame(DiscountRequestStatus::Expired, $stale->status);
        $this->assertNotNull($stale->expired_at);
        $this->assertNull($stale->decided_by, 'an expired pending request was never decided');
        $this->assertSame(DiscountRequestStatus::Pending, $fresh->status);
        $this->assertNull(DB::table('audit_logs')->where('action', 'sale_discount_expired')->where('auditable_id', $stale->id)->value('actor_id'));
    }

    // ── Expiry of pre-sale approvals ────────────────────────────────────────────────────────────

    public function test_an_approval_lasts_until_the_end_of_its_business_day_and_backdating_cannot_revive_it(): void
    {
        // 22:00 UTC is 23:00 in Lagos.
        $this->freeze('2026-10-20 22:00:00');
        $request = $this->draftRequest(saleDate: '2026-10-20');
        app(DecideSaleDiscount::class)->approve($this->admin, $request);

        // 22:59 UTC is still 23:59 on the approval day.
        $this->freeze('2026-10-20 22:59:00');
        $this->assertFalse($request->fresh()->isApprovalExpired());

        // Midnight in Lagos: the approval day has ended.
        $this->freeze('2026-10-20 23:00:01');
        $this->assertTrue($request->fresh()->isApprovalExpired());
        $this->actingAs($this->rep)->getJson(route('sales.discounts.draft.status', $request))->assertJsonPath('status', 'expired');
        $this->assertSame([], $this->actingAs($this->rep)->getJson(route('sales.discounts.draft.resumable'))->json('drafts') ?? []);

        // The approved cart is bound to 20 October, and recording it against that date is refused.
        $this->actingAs($this->rep)->post(route('sales.store'), $this->withSaleToken($this->salePayload($request, '2026-10-20')))
            ->assertSessionHasErrors(['products' => 'That discount approval has expired. Request a new discount for this sale.']);
        $this->assertSame(0, Sale::query()->count());
        $this->assertNull(SaleDraft::query()->findOrFail($request->sale_draft_id)->consumed_by_sale_id);
    }

    public function test_an_approval_spent_on_its_day_is_recorded_as_before(): void
    {
        $request = $this->draftRequest();
        app(DecideSaleDiscount::class)->approve($this->admin, $request);

        $this->actingAs($this->rep)->post(route('sales.store'), $this->withSaleToken($this->salePayload($request)))
            ->assertSessionHasNoErrors();

        $this->assertSame('300.00', Sale::query()->sole()->discount_amount);
    }

    // ── Recording expiry ────────────────────────────────────────────────────────────────────────

    public function test_the_scheduler_records_expiry_per_business_and_leaves_everything_else_alone(): void
    {
        $this->freeze('2026-10-20 09:00:00');
        $stalePending = $this->draftRequest();
        $staleApproval = $this->draftRequest();
        app(DecideSaleDiscount::class)->approve($this->admin, $staleApproval);
        $spent = $this->draftRequest();
        app(DecideSaleDiscount::class)->approve($this->admin, $spent);
        $this->actingAs($this->rep)->post(route('sales.store'), $this->withSaleToken($this->salePayload($spent, '2026-10-20')))->assertSessionHasNoErrors();

        // The scheduler runs signed out, naming each Business itself.
        auth()->logout();

        // Another Business's stale request, created in its own context.
        $other = Business::factory()->create();
        $otherRequest = app(CurrentBusiness::class)->run($other, function () use ($other): SaleDiscountRequest {
            $seller = User::factory()->forBusiness($other)->create(['role' => UserRole::SalesRep]);
            $sale = Sale::factory()->forBusiness($other)->create([
                'customer_id' => Customer::factory()->forBusiness($other)->create()->id, 'sold_by' => $seller->id,
                'status' => SaleStatus::Completed, 'subtotal' => '10000.00', 'discount_amount' => '0.00',
                'total_amount' => '10000.00', 'amount_paid' => '0.00', 'balance_due' => '10000.00',
                'payment_status' => PaymentStatus::Unpaid,
            ]);

            return app(RequestSaleDiscount::class)->execute($seller, $sale, ['amount' => '100.00', 'reason' => 'Another business entirely.']);
        });

        $this->freeze('2026-10-21 10:00:00');
        $freshPending = $this->draftRequest();
        auth()->logout();

        $this->artisan('inventra:expire-discount-requests')
            ->expectsOutput('Discount requests expired: 3')
            ->assertSuccessful();

        $this->assertSame(DiscountRequestStatus::Expired, $stalePending->fresh()->status);
        $this->assertSame(DiscountRequestStatus::Expired, $staleApproval->fresh()->status);
        $this->assertSame($this->admin->id, (int) $staleApproval->fresh()->decided_by, 'an expired approval keeps who approved it');
        $this->assertSame(DiscountRequestStatus::Approved, $spent->fresh()->status, 'a spent approval is history, not an expiry');
        $this->assertSame(DiscountRequestStatus::Pending, $freshPending->fresh()->status);
        $this->assertSame(DiscountRequestStatus::Expired,
            app(CurrentBusiness::class)->run($other, fn () => $otherRequest->fresh()->status));

        // Running again records nothing new.
        $this->artisan('inventra:expire-discount-requests')->expectsOutput('Discount requests expired: 0')->assertSuccessful();
    }

    public function test_the_database_refuses_an_inconsistent_lifecycle_row(): void
    {
        $request = $this->draftRequest();

        foreach ([
            'expired without a time' => ['status' => 'expired', 'pending_draft_guard' => null],
            'cancelled without a canceller' => ['status' => 'cancelled', 'pending_draft_guard' => null, 'decided_at' => now()],
            'self-approved by someone else' => ['status' => 'approved', 'pending_draft_guard' => null, 'decided_by' => $this->admin->id, 'decided_at' => now(), 'self_approved' => true],
        ] as $case => $change) {
            try {
                DB::table('sale_discount_requests')->where('id', $request->id)->update($change);
                $this->fail("The database accepted a request {$case}.");
            } catch (QueryException) {
                $this->assertSame('pending', DB::table('sale_discount_requests')->where('id', $request->id)->value('status'));
            }
        }
    }

    // ── The sole-Administrator exception ────────────────────────────────────────────────────────

    public function test_the_only_administrator_may_approve_their_own_request_as_an_audited_exception(): void
    {
        // A deactivated Administrator holds no seat and does not count.
        User::factory()->create(['role' => UserRole::Admin, 'status' => UserStatus::Inactive]);
        $sale = $this->completedSale();
        $request = app(RequestSaleDiscount::class)->execute($this->admin, $sale, ['amount' => '500.00', 'reason' => 'Agreed with the customer by phone.']);

        $this->assertTrue($this->admin->can('approve', $request));
        $this->actingAs($this->admin)->get(route('discounts.show', $request))->assertOk()
            ->assertSee("As the business's only administrator you may approve it yourself", false);

        $this->actingAs($this->admin)->post(route('discounts.approve', $request), ['decision_note' => 'Sole administrator.'])
            ->assertSessionHasNoErrors();

        $request->refresh();
        $this->assertSame(DiscountRequestStatus::Approved, $request->status);
        $this->assertTrue($request->self_approved);
        $this->assertSame('9500.00', $sale->fresh()->total_amount);

        $audit = DB::table('audit_logs')->where('action', 'sale_discount_approved')->where('auditable_id', $request->id)->sole();
        $this->assertTrue((bool) json_decode($audit->new_values, true)['self_approved']);
        $this->actingAs($this->admin)->get(route('discounts.show', $request))->assertSee('Self-approved');
    }

    public function test_a_second_administrator_who_is_active_or_merely_locked_restores_the_two_person_rule(): void
    {
        foreach ([UserStatus::Active, UserStatus::Locked] as $status) {
            $second = User::factory()->create(['role' => UserRole::Admin, 'status' => $status]);
            $request = $this->draftRequest($this->admin);

            $this->assertFalse($this->admin->can('approve', $request), "a {$status->value} co-administrator counts");

            try {
                app(DecideSaleDiscount::class)->approve($this->admin, $request);
                $this->fail('Self-approval must be refused while another administrator exists.');
            } catch (ValidationException $exception) {
                $this->assertSame(['You cannot approve your own discount request.'], $exception->errors()['request']);
            }

            $this->assertFalse((bool) $request->fresh()->self_approved);
            $second->forceFill(['status' => UserStatus::Inactive])->save();
        }
    }

    public function test_the_exception_and_every_way_to_add_an_administrator_share_one_lock(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $request = $this->draftRequest($this->admin);
        app(DecideSaleDiscount::class)->approve($this->admin, $request);
        DB::transaction(fn () => app(Entitlements::class)->claimRolePlace(app(CurrentBusiness::class)->forActor($this->admin), UserRole::Admin));

        $locks = array_values(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'business_subscriptions') && str_contains($sql, 'for update')));
        $this->assertCount(2, $locks, 'the self-approval and the Administrator place both lock the subscription row');
    }

    /** A second Administrator being added while the only one self-approves: the approval waits, then refuses. */
    public function test_a_concurrently_added_administrator_is_seen_by_the_self_approval(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('The pcntl extension is required for the independent-process concurrency probe.');
        }

        $request = $this->draftRequest($this->admin);
        $business = app(CurrentBusiness::class)->forActor($this->admin);
        DB::commit();

        $holding = tempnam(sys_get_temp_dir(), 'inventra-lock-');
        unlink($holding);

        try {
            $adder = pcntl_fork();
            if ($adder === 0) {
                try {
                    DB::purge();
                    DB::transaction(function () use ($business, $holding): void {
                        app(CurrentBusiness::class)->run($business, function () use ($business, $holding): void {
                            app(Entitlements::class)->claimRolePlace($business, UserRole::Admin);
                            touch($holding);
                            usleep(800_000);
                            User::factory()->forBusiness($business)->create(['role' => UserRole::Admin, 'email' => Str::random(12).'@example.com']);
                        });
                    });
                    exit(0);
                } catch (\Throwable) {
                    exit(1);
                }
            }

            $approver = pcntl_fork();
            if ($approver === 0) {
                while (! file_exists($holding)) {
                    usleep(1_000);
                }

                try {
                    DB::purge();
                    $admin = User::findOrFail($this->admin->id);
                    app(CurrentBusiness::class)->run($business, fn () => app(DecideSaleDiscount::class)->approve($admin, SaleDiscountRequest::query()->findOrFail($request->id)));
                    exit(1);
                } catch (ValidationException) {
                    exit(0);
                } catch (\Throwable) {
                    exit(2);
                }
            }

            foreach ([$adder, $approver] as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertTrue(pcntl_wifexited($status));
                $this->assertSame(0, pcntl_wexitstatus($status), 'the adder commits and the self-approval is refused');
            }

            DB::purge();
            $this->assertSame('pending', DB::table('sale_discount_requests')->where('id', $request->id)->value('status'));
        } finally {
            if (file_exists($holding)) {
                unlink($holding);
            }

            $this->rebuildTestSchema();
        }
    }
}
