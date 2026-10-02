<?php

namespace Tests\Feature\Corrections;

use App\Actions\Sale\CorrectSale;
use App\Actions\Sale\DecideSaleDiscount;
use App\Actions\Sale\RecordSaleRefund;
use App\Actions\Sale\RecordSaleReturn;
use App\Actions\Sale\RequestSaleDiscount;
use App\Actions\Sale\VoidSale;
use App\Contracts\WhatsAppConnectionProvider;
use App\Enums\InventoryMovementType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SalePaymentType;
use App\Enums\SaleStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleCorrection;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\SaleRefundRequest;
use App\Models\SaleReturnRequest;
use App\Models\User;
use App\Models\WhatsAppDelivery;
use App\Support\SaleCorrectionEligibility;
use App\Support\WhatsAppSendResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\Fakes\FakeWhatsAppProvider;
use Tests\TestCase;

/**
 * Sale correction — fixing a recording mistake.
 *
 * The Product Manager's clarification is the specification: an Administrator may correct any
 * eligible sale, a Manager only a sale they personally recorded, and a Sales Representative none at
 * all — including their own. Beyond that, the tests hold the safety properties: stock moves by
 * compensating movements rather than rewritten history, the payment ledger is never touched, money
 * is recalculated on the server from the lines, and a sale that a return or refund has already
 * reconciled against is refused rather than quietly corrupted.
 */
class SaleCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private FakeWhatsAppProvider $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = new FakeWhatsAppProvider;
        $this->client->configured = false;
        $this->app->instance(WhatsAppConnectionProvider::class, $this->client);
    }

    private function user(UserRole $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    /**
     * A completed sale of 10 units at 5,000.00 = 50,000.00, with `$paid` collected.
     *
     * @return array{0: Sale, 1: Product, 2: Customer}
     */
    private function sale(User $seller, string $paid = '0.00', string $quantity = '10.000'): array
    {
        $customer = Customer::factory()->create();
        $product = Product::factory()->create(['current_stock' => '100.000', 'selling_price' => '5000.00']);
        $total = bcmul('5000.00', $quantity, 2);

        $status = bccomp($paid, '0.00', 2) === 0
            ? PaymentStatus::Unpaid
            : (bccomp($paid, $total, 2) >= 0 ? PaymentStatus::Paid : PaymentStatus::Partial);

        $sale = Sale::factory()->create([
            'customer_id' => $customer->id,
            'sold_by' => $seller->id,
            'sold_by_name_snapshot' => $seller->name,
            'status' => SaleStatus::Completed,
            'subtotal' => $total,
            'discount_amount' => '0.00',
            'total_amount' => $total,
            'amount_paid' => $paid,
            'balance_due' => bcsub($total, $paid, 2),
            'payment_status' => $status,
        ]);

        $item = new SaleItem;
        foreach ([
            'business_id' => $sale->business_id, 'sale_id' => $sale->id, 'product_id' => $product->id,
            'product_sku_snapshot' => $product->sku, 'product_name_snapshot' => $product->name,
            'unit_snapshot' => $product->unit->value, 'quantity' => $quantity,
            'unit_price' => '5000.00', 'line_total' => $total, 'created_at' => now(),
        ] as $key => $value) {
            $item->$key = $value;
        }
        $item->save();

        // The sale took the stock when it was recorded.
        $product->current_stock = bcsub('100.000', $quantity, 3);
        $product->save();
        $movement = new InventoryMovement;
        foreach ([
            'business_id' => $product->business_id, 'product_id' => $product->id, 'type' => InventoryMovementType::Sale,
            'quantity_change' => bcsub('0', $quantity, 3), 'quantity_before' => '100.000',
            'quantity_after' => $product->current_stock, 'reference_type' => $sale->getMorphClass(),
            'reference_id' => $sale->id, 'performed_by' => $seller->id,
        ] as $key => $value) {
            $movement->$key = $value;
        }
        $movement->save();

        if (bccomp($paid, '0.00', 2) > 0) {
            $payment = new SalePayment;
            foreach ([
                'payment_number' => 'PMT-'.Str::upper(Str::random(10)), 'business_id' => $sale->business_id, 'sale_id' => $sale->id,
                'customer_id' => $customer->id, 'amount' => $paid,
                'payment_method' => PaymentMethod::Cash,
                'payment_type' => SalePaymentType::Initial,
                'recorded_by' => $seller->id, 'recorded_by_name_snapshot' => $seller->name,
                'paid_at' => now(), 'cumulative_paid_after' => $paid,
                'balance_after' => bcsub($total, $paid, 2), 'payment_status_after' => $status,
                'initial_sale_guard' => $sale->id,
            ] as $key => $value) {
                $payment->$key = $value;
            }
            $payment->save();
        }

        return [$sale->fresh(), $product->fresh(), $customer];
    }

    /** @param array<int, array{product_id: int, quantity: string}> $products */
    private function correct(User $actor, Sale $sale, array $products, string $reason = 'Recorded ten crates by mistake.'): SaleCorrection
    {
        return app(CorrectSale::class)->execute($actor, $sale, ['reason' => $reason, 'products' => $products]);
    }

    /** Asserts the database reconciliation identity still holds. */
    private function assertReconciles(Sale $sale): void
    {
        $sale = $sale->fresh();
        $left = bcadd(bcsub($sale->total_amount, $sale->returned_amount, 2), $sale->refundable_credit, 2);
        $right = bcadd(bcsub($sale->amount_paid, $sale->refunded_amount, 2), $sale->balance_due, 2);
        $this->assertSame(0, bccomp($left, $right, 2), "reconciliation identity broken: {$left} != {$right}");
        $this->assertSame(0, bccomp($sale->total_amount, bcsub($sale->subtotal, $sale->discount_amount, 2), 2),
            'total_amount must remain subtotal - discount_amount');
    }

    // ═══════════════════════════════════════ 1-9 · RBAC ═══════════════════════════════════════

    public function test_1_an_admin_can_correct_their_own_sale(): void
    {
        $admin = $this->user(UserRole::Admin);
        [$sale, $product] = $this->sale($admin);

        $this->assertTrue($admin->can('correct', $sale));
        $this->correct($admin, $sale, [['product_id' => $product->id, 'quantity' => '2.000']]);
        $this->assertSame('10000.00', $sale->fresh()->total_amount);
    }

    public function test_2_an_admin_can_correct_a_managers_sale(): void
    {
        $admin = $this->user(UserRole::Admin);
        $manager = $this->user(UserRole::Manager);
        [$sale, $product] = $this->sale($manager);

        $this->assertTrue($admin->can('correct', $sale));
        $this->correct($admin, $sale, [['product_id' => $product->id, 'quantity' => '3.000']]);
        $this->assertSame('15000.00', $sale->fresh()->total_amount);
    }

    public function test_3_an_admin_can_correct_a_sales_reps_sale(): void
    {
        $admin = $this->user(UserRole::Admin);
        $rep = $this->user(UserRole::SalesRep);
        [$sale, $product] = $this->sale($rep);

        $this->assertTrue($admin->can('correct', $sale));
        $this->correct($admin, $sale, [['product_id' => $product->id, 'quantity' => '4.000']]);
        $this->assertSame('20000.00', $sale->fresh()->total_amount);
    }

    public function test_4_a_manager_can_correct_their_own_sale(): void
    {
        $manager = $this->user(UserRole::Manager);
        [$sale, $product] = $this->sale($manager);

        $this->assertTrue($manager->can('correct', $sale));
        $this->correct($manager, $sale, [['product_id' => $product->id, 'quantity' => '2.000']]);
        $this->assertSame('10000.00', $sale->fresh()->total_amount);
    }

    public function test_5_a_manager_cannot_correct_another_managers_sale(): void
    {
        $manager = $this->user(UserRole::Manager);
        [$sale] = $this->sale($this->user(UserRole::Manager));

        $this->assertFalse($manager->can('correct', $sale));
    }

    public function test_6_a_manager_cannot_correct_a_sales_reps_sale(): void
    {
        $manager = $this->user(UserRole::Manager);
        [$sale] = $this->sale($this->user(UserRole::SalesRep));

        $this->assertFalse($manager->can('correct', $sale));
    }

    public function test_7_a_manager_cannot_correct_an_admins_sale(): void
    {
        $manager = $this->user(UserRole::Manager);
        [$sale] = $this->sale($this->user(UserRole::Admin));

        $this->assertFalse($manager->can('correct', $sale));
    }

    public function test_8_a_sales_rep_cannot_correct_their_own_sale(): void
    {
        $rep = $this->user(UserRole::SalesRep);
        [$sale] = $this->sale($rep);

        $this->assertFalse($rep->can('correct', $sale),
            'the restriction applies even to a sale the Sales Rep personally recorded');
    }

    public function test_9_a_sales_rep_cannot_correct_another_users_sale(): void
    {
        $rep = $this->user(UserRole::SalesRep);
        [$sale] = $this->sale($this->user(UserRole::Manager));

        $this->assertFalse($rep->can('correct', $sale));
    }

    // ══════════════════════════════ 10 · server-side enforcement ══════════════════════════════

    public function test_10_direct_route_access_cannot_bypass_the_policy(): void
    {
        $rep = $this->user(UserRole::SalesRep);
        $otherManager = $this->user(UserRole::Manager);
        [$repSale, $product] = $this->sale($rep);
        [$managerSale, $otherProduct] = $this->sale($otherManager);
        $manager = $this->user(UserRole::Manager);

        $payload = fn ($p) => ['reason' => 'Attempting to bypass the policy.', 'products' => [['product_id' => $p->id, 'quantity' => '1.000']]];

        // A guest first: actingAs persists across requests within a test, so asserting the guest
        // case afterwards would silently be testing an authenticated request instead.
        $this->post(route('sales.corrections.store', $repSale), $payload($product))->assertRedirect(route('login'));

        // A Sales Rep POSTing straight at the endpoint for their own sale.
        $this->actingAs($rep)->get(route('sales.corrections.create', $repSale))->assertForbidden();
        $this->actingAs($rep)->post(route('sales.corrections.store', $repSale), $payload($product))->assertForbidden();

        // A Manager POSTing straight at another Manager's sale.
        $this->actingAs($manager)->get(route('sales.corrections.create', $managerSale))->assertForbidden();
        $this->actingAs($manager)->post(route('sales.corrections.store', $managerSale), $payload($otherProduct))->assertForbidden();

        $this->assertDatabaseCount('sale_corrections', 0);
        $this->assertSame('50000.00', $repSale->fresh()->total_amount);
        $this->assertSame('50000.00', $managerSale->fresh()->total_amount);
    }

    public function test_the_action_is_hidden_from_a_sales_rep_in_the_interface(): void
    {
        $rep = $this->user(UserRole::SalesRep);
        [$sale] = $this->sale($rep);

        $html = $this->actingAs($rep)->get(route('sales.show', $sale))->assertOk()->getContent();
        $this->assertStringNotContainsString('Correct Sale', $html);
        $this->assertStringNotContainsString(route('sales.corrections.create', $sale), $html);
    }

    public function test_the_action_is_offered_to_an_eligible_manager_and_admin(): void
    {
        $manager = $this->user(UserRole::Manager);
        [$own] = $this->sale($manager);
        [$foreign] = $this->sale($this->user(UserRole::Manager));

        $ownPage = $this->actingAs($manager)->get(route('sales.show', $own))->assertOk()->getContent();
        $this->assertStringContainsString('Correct Sale', $ownPage);
        $this->assertStringContainsString('For recording mistakes only', $ownPage);

        $foreignPage = $this->actingAs($manager)->get(route('sales.show', $foreign))->assertOk()->getContent();
        $this->assertStringNotContainsString(route('sales.corrections.create', $foreign), $foreignPage);

        $adminPage = $this->actingAs($this->user(UserRole::Admin))->get(route('sales.show', $foreign))->assertOk()->getContent();
        $this->assertStringContainsString(route('sales.corrections.create', $foreign), $adminPage);
    }

    // ═══════════════════════════════ 11 · reason is mandatory ═════════════════════════════════

    public function test_11_a_correction_reason_is_mandatory(): void
    {
        $admin = $this->user(UserRole::Admin);
        [$sale, $product] = $this->sale($admin);

        foreach (['', '   ', 'too short'] as $reason) {
            $this->actingAs($admin)->post(route('sales.corrections.store', $sale), [
                'reason' => $reason,
                'products' => [['product_id' => $product->id, 'quantity' => '2.000']],
            ])->assertSessionHasErrors('reason');
        }

        $this->assertDatabaseCount('sale_corrections', 0);
        $this->assertSame('50000.00', $sale->fresh()->total_amount);
    }

    public function test_the_reason_is_stored_with_who_and_when(): void
    {
        $admin = $this->user(UserRole::Admin);
        [$sale, $product] = $this->sale($admin);

        $correction = $this->correct($admin, $sale, [['product_id' => $product->id, 'quantity' => '2.000']],
            'Customer took two crates, not ten. Counted wrong at the counter.');

        $this->assertSame('Customer took two crates, not ten. Counted wrong at the counter.', $correction->reason);
        $this->assertSame($admin->id, $correction->corrected_by);
        $this->assertSame($admin->name, $correction->corrected_by_name_snapshot);
        $this->assertNotNull($correction->corrected_at);
    }

    // ══════════════════════════ 12 · inventory reconciliation ═════════════════════════════════

    public function test_12_reducing_a_quantity_returns_stock_with_a_compensating_movement(): void
    {
        $admin = $this->user(UserRole::Admin);
        [$sale, $product] = $this->sale($admin);
        $this->assertSame('90.000', $product->current_stock, 'the sale took 10');

        $correction = $this->correct($admin, $sale, [['product_id' => $product->id, 'quantity' => '2.000']]);

        // 8 units come back.
        $this->assertSame('98.000', $product->fresh()->current_stock);

        $movement = InventoryMovement::query()->where('type', InventoryMovementType::Correction)->sole();
        $this->assertSame('8.000', $movement->quantity_change);
        $this->assertSame('90.000', $movement->quantity_before);
        $this->assertSame('98.000', $movement->quantity_after);
        $this->assertSame($admin->id, $movement->performed_by);
        $this->assertStringContainsString('Sale correction '.$correction->id, $movement->reason);

        // The original sale movement is untouched historical evidence.
        $original = InventoryMovement::query()->where('type', InventoryMovementType::Sale)->sole();
        $this->assertSame('-10.000', $original->quantity_change);
        $this->assertSame('90.000', $original->quantity_after);
    }

    public function test_increasing_a_quantity_takes_more_stock(): void
    {
        $admin = $this->user(UserRole::Admin);
        [$sale, $product] = $this->sale($admin);

        $this->correct($admin, $sale, [['product_id' => $product->id, 'quantity' => '15.000']]);

        $this->assertSame('85.000', $product->fresh()->current_stock);
        $this->assertSame('-5.000', InventoryMovement::query()->where('type', InventoryMovementType::Correction)->sole()->quantity_change);
        $this->assertSame('75000.00', $sale->fresh()->total_amount);
    }

    public function test_swapping_the_product_moves_both_products_stock(): void
    {
        $admin = $this->user(UserRole::Admin);
        [$sale, $wrong] = $this->sale($admin);
        $right = Product::factory()->create(['current_stock' => '40.000', 'selling_price' => '2000.00']);

        $this->correct($admin, $sale, [['product_id' => $right->id, 'quantity' => '10.000']],
            'Rang up the wrong product entirely.');

        $this->assertSame('100.000', $wrong->fresh()->current_stock, 'the wrong product is made whole');
        $this->assertSame('30.000', $right->fresh()->current_stock, 'the right product is drawn down');
        $this->assertSame('20000.00', $sale->fresh()->total_amount, 'priced from the new product');
        $this->assertSame(2, InventoryMovement::query()->where('type', InventoryMovementType::Correction)->count());
    }

    public function test_a_correction_cannot_drive_stock_negative(): void
    {
        $admin = $this->user(UserRole::Admin);
        [$sale, $product] = $this->sale($admin);

        // Only 90 on hand beyond what this sale already took.
        $this->expectException(ValidationException::class);
        $this->correct($admin, $sale, [['product_id' => $product->id, 'quantity' => '200.000']]);
    }

    public function test_an_unchanged_quantity_creates_no_stock_movement(): void
    {
        $admin = $this->user(UserRole::Admin);
        [$sale, $product] = $this->sale($admin);

        $this->correct($admin, $sale, [['product_id' => $product->id, 'quantity' => '10.000']],
            'Corrected the customer, quantities unchanged.');

        $this->assertSame('90.000', $product->fresh()->current_stock);
        $this->assertSame(0, InventoryMovement::query()->where('type', InventoryMovementType::Correction)->count());
    }

    // ═════════════════════════ 13-14 · financial reconciliation ═══════════════════════════════

    public function test_13_totals_are_recalculated_server_side_and_client_figures_are_refused(): void
    {
        $admin = $this->user(UserRole::Admin);
        [$sale, $product] = $this->sale($admin);

        $this->actingAs($admin)->post(route('sales.corrections.store', $sale), [
            'reason' => 'Trying to dictate the totals from the client.',
            'products' => [['product_id' => $product->id, 'quantity' => '2.000']],
            'subtotal' => '1.00', 'total_amount' => '1.00', 'amount_paid' => '999.00',
            'balance_due' => '0.00', 'payment_status' => 'paid',
        ])->assertSessionHasErrors(['subtotal', 'total_amount', 'amount_paid', 'balance_due', 'payment_status']);

        $this->assertDatabaseCount('sale_corrections', 0);

        // And through the legitimate path the server does the arithmetic itself.
        $this->correct($admin, $sale, [['product_id' => $product->id, 'quantity' => '2.000']]);
        $sale = $sale->fresh();
        $this->assertSame('10000.00', $sale->subtotal);
        $this->assertSame('10000.00', $sale->total_amount);
        $this->assertReconciles($sale);
    }

    public function test_14_historical_payment_evidence_is_never_rewritten(): void
    {
        $admin = $this->user(UserRole::Admin);
        [$sale, $product] = $this->sale($admin, '50000.00');
        $paymentsBefore = SalePayment::query()->where('sale_id', $sale->id)->get()->toArray();

        // Corrected down to 2 units = 10,000.00, but 50,000.00 was already paid.
        $this->correct($admin, $sale, [['product_id' => $product->id, 'quantity' => '2.000']]);

        $sale = $sale->fresh();
        $this->assertSame($paymentsBefore, SalePayment::query()->where('sale_id', $sale->id)->get()->toArray(),
            'no sale_payments row may be altered by a correction');
        $this->assertSame('50000.00', $sale->amount_paid, 'what was paid is historical fact');
        $this->assertSame('10000.00', $sale->total_amount);
        $this->assertSame('0.00', $sale->balance_due, 'an overpayment never becomes a negative balance');
        $this->assertSame('40000.00', $sale->refundable_credit,
            'the overpayment becomes refundable credit through the existing model');
        $this->assertSame(PaymentStatus::Paid, $sale->payment_status);
        $this->assertReconciles($sale);
    }

    public function test_the_credit_a_correction_creates_can_be_refunded(): void
    {
        $admin = $this->user(UserRole::Admin);
        [$sale, $product] = $this->sale($admin, '50000.00');
        $this->correct($admin, $sale, [['product_id' => $product->id, 'quantity' => '2.000']]);

        $token = Str::random(64);
        $request = new SaleRefundRequest;
        foreach (['token_hash' => hash('sha256', $token), 'sale_id' => $sale->id, 'actor_id' => $admin->id,
            'session_id' => 'test', 'expires_at' => now()->addMinute()] as $k => $v) {
            $request->$k = $v;
        }
        $request->save();

        $refund = app(RecordSaleRefund::class)->execute($admin, $sale->fresh(), [
            'request_token' => $token, 'amount' => '40000.00', 'payment_method' => 'cash',
            'reason' => 'Refund of an over-recorded sale.',
        ], 'test');

        $this->assertSame('40000.00', $refund->amount);
        $this->assertSame('0.00', $sale->fresh()->refundable_credit);
        $this->assertSame('50000.00', $sale->fresh()->amount_paid);
        $this->assertReconciles($sale);
    }

    public function test_a_correction_below_an_existing_discount_is_refused(): void
    {
        $admin = $this->user(UserRole::Admin);
        [$sale, $product] = $this->sale($admin);
        // Force a discount straight onto the sale to reach the guard without the approval workflow,
        // which would itself block correction.
        // balance_due must move with the total, or the reconcile CHECK rejects the seed itself.
        DB::table('sales')->where('id', $sale->id)->update([
            'discount_amount' => '20000.00', 'total_amount' => '30000.00', 'balance_due' => '30000.00',
        ]);

        $this->expectException(ValidationException::class);
        $this->correct($admin, $sale->fresh(), [['product_id' => $product->id, 'quantity' => '1.000']]);
    }

    public function test_a_correction_cannot_remove_every_line(): void
    {
        $admin = $this->user(UserRole::Admin);
        [$sale, $product] = $this->sale($admin);

        $this->expectException(ValidationException::class);
        $this->correct($admin, $sale, [['product_id' => $product->id, 'quantity' => '0.000']]);
    }

    // ═══════════════════════ 15 · downstream conflicts are blocked ════════════════════════════

    public function test_15_a_sale_with_a_return_cannot_be_corrected(): void
    {
        $admin = $this->user(UserRole::Admin);
        [$sale, $product] = $this->sale($admin, '50000.00');
        $item = $sale->items()->sole();

        $token = Str::random(64);
        $request = new SaleReturnRequest;
        foreach (['token_hash' => hash('sha256', $token), 'sale_id' => $sale->id, 'actor_id' => $admin->id,
            'session_id' => 'test', 'expires_at' => now()->addMinute()] as $k => $v) {
            $request->$k = $v;
        }
        $request->save();
        app(RecordSaleReturn::class)->execute($admin, $sale->fresh(), [
            'request_token' => $token, 'reason' => 'Customer returned two crates.',
            'items' => [['sale_item_id' => $item->id, 'quantity' => '2.000', 'disposition' => 'restock']],
        ], 'test');

        $reason = SaleCorrectionEligibility::blockedReason($sale->fresh());
        $this->assertNotNull($reason);
        $this->assertStringContainsString('Return workflow', $reason, 'the message must point at the right workflow');

        $this->expectException(ValidationException::class);
        $this->correct($admin, $sale->fresh(), [['product_id' => $product->id, 'quantity' => '1.000']]);
    }

    public function test_15b_a_sale_with_a_refund_cannot_be_corrected(): void
    {
        $admin = $this->user(UserRole::Admin);
        [$sale, $product] = $this->sale($admin, '50000.00');
        $item = $sale->items()->sole();

        foreach ([[SaleReturnRequest::class, 'return'], [SaleRefundRequest::class, 'refund']] as [$class, $kind]) {
            $token = Str::random(64);
            $request = new $class;
            foreach (['token_hash' => hash('sha256', $token), 'sale_id' => $sale->id, 'actor_id' => $admin->id,
                'session_id' => 'test', 'expires_at' => now()->addMinute()] as $k => $v) {
                $request->$k = $v;
            }
            $request->save();

            if ($kind === 'return') {
                app(RecordSaleReturn::class)->execute($admin, $sale->fresh(), [
                    'request_token' => $token, 'reason' => 'Returned.',
                    'items' => [['sale_item_id' => $item->id, 'quantity' => '2.000', 'disposition' => 'non_restock']],
                ], 'test');
            } else {
                app(RecordSaleRefund::class)->execute($admin, $sale->fresh(), [
                    'request_token' => $token, 'amount' => '5000.00', 'payment_method' => 'cash', 'reason' => 'Refunded.',
                ], 'test');
            }
        }

        $this->assertNotNull(SaleCorrectionEligibility::blockedReason($sale->fresh()));
        $this->expectException(ValidationException::class);
        $this->correct($admin, $sale->fresh(), [['product_id' => $product->id, 'quantity' => '1.000']]);
    }

    public function test_15c_a_pending_or_approved_discount_blocks_correction(): void
    {
        $admin = $this->user(UserRole::Admin);
        $manager = $this->user(UserRole::Manager);
        [$sale, $product] = $this->sale($admin);

        $request = app(RequestSaleDiscount::class)->execute($manager, $sale, [
            'amount' => '5000.00', 'reason' => 'Goodwill for a late delivery.',
        ]);

        $pendingReason = SaleCorrectionEligibility::blockedReason($sale->fresh());
        $this->assertStringContainsString('awaiting a decision', $pendingReason);

        app(DecideSaleDiscount::class)->approve($admin, $request);

        $approvedReason = SaleCorrectionEligibility::blockedReason($sale->fresh());
        $this->assertStringContainsString('approved discount', $approvedReason);

        $this->expectException(ValidationException::class);
        $this->correct($admin, $sale->fresh(), [['product_id' => $product->id, 'quantity' => '1.000']]);
    }

    public function test_15d_a_voided_sale_cannot_be_corrected(): void
    {
        $admin = $this->user(UserRole::Admin);
        [$sale, $product] = $this->sale($admin);
        app(VoidSale::class)->execute($admin, $sale->fresh(), 'Recorded against the wrong customer.');

        $this->expectException(ValidationException::class);
        $this->correct($admin, $sale->fresh(), [['product_id' => $product->id, 'quantity' => '1.000']]);
    }

    public function test_the_blocked_reason_is_shown_instead_of_the_action(): void
    {
        $admin = $this->user(UserRole::Admin);
        [$sale] = $this->sale($admin, '50000.00');
        $item = $sale->items()->sole();
        $token = Str::random(64);
        $request = new SaleReturnRequest;
        foreach (['token_hash' => hash('sha256', $token), 'sale_id' => $sale->id, 'actor_id' => $admin->id,
            'session_id' => 'test', 'expires_at' => now()->addMinute()] as $k => $v) {
            $request->$k = $v;
        }
        $request->save();
        app(RecordSaleReturn::class)->execute($admin, $sale->fresh(), [
            'request_token' => $token, 'reason' => 'Returned.',
            'items' => [['sale_item_id' => $item->id, 'quantity' => '1.000', 'disposition' => 'restock']],
        ], 'test');

        $html = $this->actingAs($admin)->get(route('sales.show', $sale))->assertOk()->getContent();
        $this->assertStringContainsString('cannot be corrected', $html);
        $this->assertStringNotContainsString(route('sales.corrections.create', $sale), $html);
    }

    // ═══════════════════════════════ 16 · audit evidence ══════════════════════════════════════

    public function test_16_before_and_after_evidence_is_recorded_and_auditable(): void
    {
        $admin = $this->user(UserRole::Admin);
        [$sale, $product] = $this->sale($admin, '50000.00');

        $correction = $this->correct($admin, $sale, [['product_id' => $product->id, 'quantity' => '2.000']],
            'Counted ten crates but the customer took two.');

        // On the correction record itself.
        $this->assertSame('50000.00', $correction->subtotal_before);
        $this->assertSame('10000.00', $correction->subtotal_after);
        $this->assertSame('50000.00', $correction->total_before);
        $this->assertSame('10000.00', $correction->total_after);
        $this->assertSame('0.00', $correction->balance_before);
        $this->assertSame('0.00', $correction->balance_after);
        $this->assertSame('0.00', $correction->refundable_credit_before);
        $this->assertSame('40000.00', $correction->refundable_credit_after);
        $this->assertSame(1, $correction->item_count_before);
        $this->assertSame(1, $correction->item_count_after);

        // And in the existing audit trail.
        $log = AuditLog::query()->where('action', 'sale_corrected')->sole();
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame('50000.00', $log->old_values['total_amount']);
        $this->assertSame('10000.00', $log->new_values['total_amount']);
        $this->assertSame('50000.00', $log->new_values['amount_paid']);
        $this->assertSame('40000.00', $log->new_values['refundable_credit']);
        $this->assertSame('Counted ten crates but the customer took two.', $log->metadata['reason']);
    }

    public function test_superseded_lines_are_kept_rather_than_deleted(): void
    {
        $admin = $this->user(UserRole::Admin);
        [$sale, $product] = $this->sale($admin);
        $originalItemId = $sale->items()->sole()->id;

        $correction = $this->correct($admin, $sale, [['product_id' => $product->id, 'quantity' => '2.000']]);

        $sale = $sale->fresh();
        $this->assertCount(2, $sale->allItems, 'the original line is retained');
        $this->assertCount(1, $sale->items, 'only the corrected line is active');

        $original = SaleItem::query()->findOrFail($originalItemId);
        $this->assertSame('10.000', $original->quantity, 'the original line was not edited');
        $this->assertSame($correction->id, $original->superseded_by_correction_id);

        $replacement = $sale->items->first();
        $this->assertSame('2.000', $replacement->quantity);
        $this->assertSame($correction->id, $replacement->sale_correction_id);
    }

    public function test_sale_items_remain_append_only_outside_a_correction(): void
    {
        $admin = $this->user(UserRole::Admin);
        [$sale] = $this->sale($admin);
        $item = $sale->items()->sole();

        $this->expectException(LogicException::class);
        $item->forceFill(['quantity' => '1.000'])->save();
    }

    public function test_a_correction_record_is_itself_immutable(): void
    {
        $admin = $this->user(UserRole::Admin);
        [$sale, $product] = $this->sale($admin);
        $correction = $this->correct($admin, $sale, [['product_id' => $product->id, 'quantity' => '2.000']]);

        $this->expectException(LogicException::class);
        $correction->forceFill(['reason' => 'Rewriting the reason.'])->save();
    }

    public function test_the_correction_history_page_shows_what_changed(): void
    {
        $admin = $this->user(UserRole::Admin);
        [$sale, $product] = $this->sale($admin);
        $this->correct($admin, $sale, [['product_id' => $product->id, 'quantity' => '2.000']],
            'Counted ten crates but the customer took two.');

        $html = $this->actingAs($admin)->get(route('sales.corrections.index', $sale))->assertOk()->getContent();
        $this->assertStringContainsString('Counted ten crates but the customer took two.', $html);
        $this->assertStringContainsString('Lines replaced', $html);
        $this->assertStringContainsString('Lines recorded instead', $html);
        $this->assertStringContainsString('50,000.00', $html);
        $this->assertStringContainsString('10,000.00', $html);
    }

    // ═══════════ 17-20 · existing workflows keep working after a correction ═══════════════════

    public function test_17_the_return_workflow_still_works_on_a_corrected_sale(): void
    {
        $admin = $this->user(UserRole::Admin);
        [$sale, $product] = $this->sale($admin, '50000.00');
        $this->correct($admin, $sale, [['product_id' => $product->id, 'quantity' => '4.000']]);

        // Returns operate on the corrected lines, not the superseded ones.
        $active = $sale->fresh()->items()->sole();
        $this->assertSame('4.000', $active->quantity);

        $token = Str::random(64);
        $request = new SaleReturnRequest;
        foreach (['token_hash' => hash('sha256', $token), 'sale_id' => $sale->id, 'actor_id' => $admin->id,
            'session_id' => 'test', 'expires_at' => now()->addMinute()] as $k => $v) {
            $request->$k = $v;
        }
        $request->save();

        $return = app(RecordSaleReturn::class)->execute($admin, $sale->fresh(), [
            'request_token' => $token, 'reason' => 'Customer returned one crate.',
            'items' => [['sale_item_id' => $active->id, 'quantity' => '1.000', 'disposition' => 'restock']],
        ], 'test');

        $this->assertSame('5000.00', $return->merchandise_value);
        $this->assertReconciles($sale);

        // The return form renders the corrected lines only.
        $form = $this->actingAs($admin)->get(route('sales.returns.create', $sale))->assertOk()->getContent();
        // Quantities display trimmed on the return form too.
        $this->assertStringContainsString('Original 4 ', $form);
        $this->assertStringNotContainsString('Original 10 ', $form);
    }

    public function test_18_the_discount_workflow_still_works_on_a_corrected_sale(): void
    {
        $admin = $this->user(UserRole::Admin);
        $manager = $this->user(UserRole::Manager);
        [$sale, $product] = $this->sale($admin);
        $this->correct($admin, $sale, [['product_id' => $product->id, 'quantity' => '4.000']]);
        $this->assertSame('20000.00', $sale->fresh()->total_amount);

        $request = app(RequestSaleDiscount::class)->execute($manager, $sale->fresh(), [
            'amount' => '2000.00', 'reason' => 'Goodwill after the mix-up.',
        ]);
        app(DecideSaleDiscount::class)->approve($admin, $request);

        $sale = $sale->fresh();
        $this->assertSame('20000.00', $sale->subtotal);
        $this->assertSame('2000.00', $sale->discount_amount);
        $this->assertSame('18000.00', $sale->total_amount);
        $this->assertReconciles($sale);
    }

    public function test_19_the_receipt_renders_and_shows_the_corrected_figures(): void
    {
        $admin = $this->user(UserRole::Admin);
        [$sale, $product] = $this->sale($admin, '50000.00');

        $before = $this->actingAs($admin)->get(route('sales.receipt', $sale))->assertOk()->getContent();
        $this->assertStringContainsString('50,000.00', $before);

        $this->correct($admin, $sale, [['product_id' => $product->id, 'quantity' => '2.000']]);

        $after = $this->actingAs($admin)->get(route('sales.receipt', $sale))->assertOk()->getContent();
        $this->assertStringContainsString('10,000.00', $after, 'the receipt must show the corrected total');
        // Receipts display quantities trimmed: "2", not "2.000".
        $this->assertStringContainsString('>2 ', $after, 'and the corrected quantity');
        $this->assertStringNotContainsString('>10 ', $after, 'the superseded line must not appear');
        $this->assertStringContainsString('supersedes any receipt issued earlier', $after);
        $this->assertStringContainsString('data-print-trigger', $after, 'printing still works');
    }

    public function test_20_whatsapp_history_survives_and_no_receipt_is_auto_resent(): void
    {
        $admin = $this->user(UserRole::Admin);
        [$sale, $product, $customer] = $this->sale($admin, '50000.00');
        $customer->forceFill([
            'whatsapp_opt_in' => true, 'whatsapp_opt_in_at' => now()->subMinute(), 'whatsapp_opt_out_at' => null,
        ])->save();

        // A receipt was sent before the correction.
        $this->client->configured = true;
        $this->client->result = WhatsAppSendResult::accepted('wamid.before-correction');
        $token = (string) Str::uuid();
        $this->actingAs($admin)->withSession(['whatsapp.send.'.$sale->id => $token])
            ->post(route('sales.whatsapp.send', $sale), ['request_token' => $token])->assertRedirect();
        $this->assertCount(1, $this->client->requests);

        $this->correct($admin, $sale, [['product_id' => $product->id, 'quantity' => '2.000']]);

        // The correction sends nothing on its own: there is no product rule for auto-replacing a
        // receipt a customer has already received.
        $this->assertCount(1, $this->client->requests, 'a correction must not auto-send a replacement receipt');
        $this->assertSame(1, WhatsAppDelivery::query()->count(), 'the earlier delivery record is preserved');

        // Manual re-send remains available, and sends the corrected receipt.
        $resendToken = (string) Str::uuid();
        $this->actingAs($admin)->withSession(['whatsapp.send.'.$sale->id => $resendToken])
            ->post(route('sales.whatsapp.send', $sale), ['request_token' => $resendToken])->assertRedirect();

        $this->assertCount(2, $this->client->requests);
        $this->assertSame(2, WhatsAppDelivery::query()->count());
        $this->assertSame(2, WhatsAppDelivery::query()->max('attempt'));
    }

    // ═════════════════════════════════ customer correction ════════════════════════════════════

    public function test_the_customer_can_be_corrected_while_no_payment_exists(): void
    {
        $admin = $this->user(UserRole::Admin);
        [$sale, $product] = $this->sale($admin, '0.00');
        $right = Customer::factory()->create();

        app(CorrectSale::class)->execute($admin, $sale, [
            'reason' => 'Recorded against the wrong customer at the counter.',
            'customer_id' => $right->id,
            'products' => [['product_id' => $product->id, 'quantity' => '10.000']],
        ]);

        $sale = $sale->fresh();
        $this->assertSame($right->id, $sale->customer_id);
        $this->assertSame($right->full_name, $sale->customer_name_snapshot);
        $this->assertSame($right->customer_code, $sale->customer_code_snapshot);
    }

    public function test_the_customer_cannot_be_changed_once_a_payment_exists(): void
    {
        $admin = $this->user(UserRole::Admin);
        [$sale, $product] = $this->sale($admin, '50000.00');
        $other = Customer::factory()->create();

        $this->expectException(ValidationException::class);
        app(CorrectSale::class)->execute($admin, $sale, [
            'reason' => 'Trying to move a paid sale to another customer.',
            'customer_id' => $other->id,
            'products' => [['product_id' => $product->id, 'quantity' => '10.000']],
        ]);
    }

    public function test_the_form_explains_why_the_customer_is_locked(): void
    {
        $admin = $this->user(UserRole::Admin);
        [$sale] = $this->sale($admin, '50000.00');

        $html = $this->actingAs($admin)->get(route('sales.corrections.create', $sale))->assertOk()->getContent();
        $this->assertStringContainsString('cannot be changed because a payment has already been recorded', $html);
        $this->assertStringContainsString('Record Return', $html, 'the form must point at the Return workflow');
        $this->assertStringContainsString('not how you record returned goods', $html);
    }
}
