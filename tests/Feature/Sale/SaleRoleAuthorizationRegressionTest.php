<?php

namespace Tests\Feature\Sale;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SalePaymentType;
use App\Enums\SaleStatus;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\User;
use App\Policies\SalePolicy;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

/**
 * Locks down who may do what to a Sale that somebody else recorded.
 *
 * Two boundaries are pinned here.
 *
 * The first is what a Manager may do to a colleague's sale through the everyday workflows — take a
 * payment, record a return, send a receipt, read the audit trail. That has always worked and must
 * keep working.
 *
 * The second replaces a superseded assumption. This suite used to assert that a completed Sale
 * could never be changed at all. The Product Manager has since clarified that recording mistakes
 * must be correctable: an Admin may correct any sale, a Manager only their own, and a Sales Rep
 * none. So the assertions below no longer claim sale editing is absent; they claim it is reachable
 * only through the controlled correction workflow, and that a raw model rewrite is still refused.
 * Correction RBAC itself is covered in depth by SaleCorrectionTest.
 */
class SaleRoleAuthorizationRegressionTest extends TestCase
{
    use DatabaseTransactions;

    private function seller(): User
    {
        return User::factory()->create(['role' => UserRole::SalesRep]);
    }

    /** A completed, part-paid sale belonging to `$seller`, with room for returns and refunds. */
    private function saleOf(User $seller): Sale
    {
        $customer = Customer::factory()->create();
        $product = Product::factory()->create(['current_stock' => '10.000']);

        $sale = Sale::factory()->create([
            'customer_id' => $customer->id,
            'sold_by' => $seller->id,
            'status' => SaleStatus::Completed,
            'subtotal' => '100000.00',
            'discount_amount' => '0.00',
            'total_amount' => '100000.00',
            'amount_paid' => '60000.00',
            'balance_due' => '40000.00',
            'payment_status' => PaymentStatus::Partial,
        ]);

        $item = new SaleItem;
        foreach ([
            'business_id' => $sale->business_id, 'sale_id' => $sale->id, 'product_id' => $product->id,
            'product_sku_snapshot' => $product->sku, 'product_name_snapshot' => $product->name,
            'unit_snapshot' => $product->unit->value, 'quantity' => '2.000',
            'unit_price' => '50000.00', 'line_total' => '100000.00', 'created_at' => now(),
        ] as $key => $value) {
            $item->$key = $value;
        }
        $item->save();

        $payment = new SalePayment;
        foreach ([
            'payment_number' => 'PMT-'.Str::upper(Str::random(10)), 'business_id' => $sale->business_id, 'sale_id' => $sale->id,
            'customer_id' => $customer->id, 'amount' => '60000.00',
            'payment_method' => PaymentMethod::Cash, 'payment_type' => SalePaymentType::Initial,
            'recorded_by' => $seller->id, 'recorded_by_name_snapshot' => $seller->name,
            'paid_at' => now(), 'cumulative_paid_after' => '60000.00', 'balance_after' => '40000.00',
            'payment_status_after' => PaymentStatus::Partial, 'initial_sale_guard' => $sale->id,
        ] as $key => $value) {
            $payment->$key = $value;
        }
        $payment->save();

        return $sale;
    }

    // ───────────────── sale changes exist, but only through controlled workflows ────────────────

    public function test_sale_changes_are_reachable_only_through_named_controlled_workflows(): void
    {
        $names = collect(app('router')->getRoutes())->map(fn ($route) => $route->getName())->filter();

        // No generic CRUD update: changing a Sale goes through a workflow that states its purpose,
        // demands a reason and recalculates the money on the server.
        $this->assertFalse($names->contains('sales.edit'), 'there must be no generic sale edit route');
        $this->assertFalse($names->contains('sales.update'), 'there must be no generic sale update route');

        // The controlled alternatives that replaced the blanket prohibition.
        $this->assertTrue($names->contains('sales.corrections.create'));
        $this->assertTrue($names->contains('sales.corrections.store'));
        $this->assertTrue($names->contains('sales.discounts.store'));

        // Correcting is an ability in its own right, distinct from viewing or voting on money.
        $this->assertTrue(method_exists(SalePolicy::class, 'correct'));
        $this->assertFalse(method_exists(SalePolicy::class, 'update'),
            'a generic update ability would reintroduce unrestricted CRUD');
    }

    public function test_the_model_still_refuses_a_raw_financial_rewrite_even_from_an_admin(): void
    {
        $sale = $this->saleOf($this->seller());

        // Correction did not open a hole. An arbitrary write to the model is still refused; only
        // the sanctioned transitions may move these columns, and each recalculates the rest.
        $this->expectException(LogicException::class);
        $sale->forceFill(['total_amount' => '1.00'])->save();
    }

    public function test_amount_paid_cannot_be_rewritten_by_any_sanctioned_path(): void
    {
        $sale = $this->saleOf($this->seller());

        // What the customer handed over is derived from the immutable payment ledger. Neither
        // correction nor discount is permitted to move it directly.
        $this->expectException(LogicException::class);
        $sale->forceFill(['amount_paid' => '999999.00'])->save();
    }

    // ───────────────────── a Manager may act on another employee's sale ─────────────────────────

    public function test_a_manager_can_use_every_permitted_action_on_another_employees_sale(): void
    {
        $seller = $this->seller();
        $sale = $this->saleOf($seller);
        $manager = User::factory()->create(['role' => UserRole::Manager]);

        $this->assertNotSame($manager->id, $sale->sold_by);

        // Reading it
        $this->actingAs($manager)->get(route('sales.show', $sale))->assertOk();
        $this->actingAs($manager)->get(route('sales.receipt', $sale))->assertOk();
        $this->actingAs($manager)->get(route('sales.activity', $sale))->assertOk();
        $this->actingAs($manager)->get(route('sales.index'))->assertOk();

        // Taking money against it, through the real form the Manager is served
        $this->assertTrue($manager->can('recordPayment', $sale));
        $this->actingAs($manager)->startSession();
        $page = $this->get(route('sales.show', $sale))->assertOk()->getContent();
        $formAction = preg_quote(route('sales.payments.store', $sale), '/');
        preg_match('/action="'.$formAction.'".*?name="request_token" value="([A-Za-z0-9]{64})"/s', $page, $matches);
        $this->assertArrayHasKey(1, $matches, 'a Manager must be offered the payment form on a colleague\'s sale');

        // The confirmation token is bound to the session that was issued it, so pin that session
        // for the POST exactly as a browser would carry its cookie.
        $issued = DB::table('sale_payment_requests')->where('token_hash', hash('sha256', $matches[1]))->firstOrFail();
        $this->assertSame($manager->id, (int) $issued->actor_id);
        $this->withCookie(config('session.cookie'), $issued->session_id);

        $this->post(route('sales.payments.store', $sale), [
            'request_token' => $matches[1], 'amount' => '10000.00', 'payment_method' => 'cash', 'note' => null,
        ])->assertRedirect()->assertSessionDoesntHaveErrors();
        $this->assertSame('70000.00', $sale->fresh()->amount_paid);
        $this->assertSame('30000.00', $sale->fresh()->balance_due);

        // Returns and refunds against it
        $this->actingAs($manager)->get(route('sales.returns.create', $sale))->assertOk();

        // And the audit trail for it
        $this->assertTrue($manager->can('viewAudit', $sale));

        // Correction is the exception: a Manager may act on a colleague's sale in every way above,
        // but may not correct one they did not personally record.
        $this->assertFalse($manager->can('correct', $sale),
            'a Manager may only correct a sale they personally recorded');
    }

    public function test_a_manager_may_not_void_a_sale(): void
    {
        $sale = $this->saleOf($this->seller());
        $manager = User::factory()->create(['role' => UserRole::Manager]);

        $this->assertFalse($manager->can('void', $sale), 'voiding stays with an Admin');
        $this->actingAs($manager)->post(route('sales.void', $sale), ['reason' => 'Manager attempt to void.'])
            ->assertForbidden();
        $this->assertSame(SaleStatus::Completed, $sale->fresh()->status);
    }

    // ────────────────────────── a Sales Rep stays inside their own work ─────────────────────────

    public function test_a_sales_rep_is_confined_to_their_own_sales(): void
    {
        $owner = $this->seller();
        $other = $this->seller();
        $own = $this->saleOf($owner);
        $foreign = $this->saleOf($other);

        // Their own sale is fully reachable.
        $this->actingAs($owner)->get(route('sales.show', $own))->assertOk();
        $this->actingAs($owner)->get(route('sales.receipt', $own))->assertOk();
        $this->assertTrue($owner->can('recordPayment', $own));

        // A colleague's is not.
        $this->actingAs($owner)->get(route('sales.show', $foreign))->assertForbidden();
        $this->actingAs($owner)->get(route('sales.receipt', $foreign))->assertForbidden();
        $this->assertFalse($owner->can('recordPayment', $foreign));
        $this->assertFalse($owner->can('viewAudit', $own), 'the audit trail is a management view');
        $this->assertFalse($owner->can('void', $own));
        $this->assertFalse($owner->can('correct', $own),
            'a Sales Rep cannot correct a sale, not even one they recorded themselves');

        // Nor may they reach management-only workflows on any sale.
        $this->actingAs($owner)->get(route('sales.returns.create', $own))->assertForbidden();
        $this->actingAs($owner)->get(route('sales.activity', $foreign))->assertForbidden();
    }

    public function test_the_sales_list_hides_other_peoples_sales_from_a_sales_rep(): void
    {
        $owner = $this->seller();
        $own = $this->saleOf($owner);
        $foreign = $this->saleOf($this->seller());

        $html = $this->actingAs($owner)->get(route('sales.index'))->assertOk()->getContent();

        $this->assertStringContainsString($own->sale_number, $html);
        $this->assertStringNotContainsString($foreign->sale_number, $html);
    }

    // ───────────────────────────────── an Admin retains authority ───────────────────────────────

    public function test_an_admin_retains_full_authority_over_any_sale(): void
    {
        $sale = $this->saleOf($this->seller());
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)->get(route('sales.show', $sale))->assertOk();
        $this->actingAs($admin)->get(route('sales.activity', $sale))->assertOk();
        $this->assertTrue($admin->can('recordPayment', $sale));
        $this->assertTrue($admin->can('viewAudit', $sale));
        $this->assertTrue($admin->can('void', $sale));
        $this->assertTrue($admin->can('correct', $sale), 'an Admin may correct any eligible sale');

        $this->actingAs($admin)->post(route('sales.void', $sale), ['reason' => 'Recorded against the wrong customer.'])
            ->assertRedirect();
        $this->assertSame(SaleStatus::Voided, $sale->fresh()->status);
    }
}
