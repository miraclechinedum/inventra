<?php

namespace Tests\Feature\Customers;

use App\Actions\Sale\CreateSale;
use App\Actions\Sale\IssueSalePaymentRequest;
use App\Actions\Sale\RecordSalePayment;
use App\Actions\Sale\VoidSale;
use App\Enums\UserRole;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The customer profile's Balances & payments panel.
 *
 * What is owed comes from the Receivables report's own scope (completed sales with a balance due),
 * payments from completed sales only, and a Sales Representative sees only the sales they recorded.
 */
class CustomerBalancesPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame(
            'inventra_test',
            DB::connection()->getDatabaseName(),
            'Refusing to run: these tests write, and this is not the test database.'
        );

        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->customer = Customer::factory()->create(['is_active' => true]);
    }

    public function test_the_panel_shows_what_is_owed_and_what_was_paid_on_completed_sales(): void
    {
        $older = $this->sale($this->admin, '1000.00', '0.00', '2026-09-01');
        $partial = $this->sale($this->admin, '2500.00', '1000.00', '2026-09-05');
        $paid = $this->sale($this->admin, '400.00', '400.00', '2026-09-07');
        $voided = $this->sale($this->admin, '9000.00', '3000.00', '2026-09-08');
        app(VoidSale::class)->execute($this->admin, $voided->fresh(), 'Recorded against the wrong customer.');
        $settlement = $this->settle($partial, '500.00', 'PRIVATE PAYMENT NOTE');

        $page = $this->actingAs($this->admin)->get(route('customers.show', $this->customer))->assertOk();
        $receivables = $page->viewData('receivables');

        // 1,000 unpaid + 1,000 still due on the part-paid sale; the voided sale owes nothing.
        $this->assertSame('2000.00', $receivables['outstanding']);
        $this->assertSame(2, $receivables['open_count']);
        $this->assertSame(1, $receivables['unpaid_count']);
        $this->assertSame(1, $receivables['partial_count']);
        $this->assertSame([$older->id, $partial->id], $receivables['sales']->pluck('id')->all(), 'oldest balance first');

        $payments = $page->viewData('payments');
        $this->assertSame(
            [$settlement->id, $paid->payments()->sole()->id, $partial->payments()->oldest('id')->first()->id],
            $payments->pluck('id')->all(),
            'payments on completed sales, newest first',
        );
        $this->assertNotContains($voided->payments()->sole()->id, $payments->pluck('id')->all());

        $page->assertSee('Balances &amp; payments', false)
            ->assertSee('&#8358;2,000.00', false)
            ->assertSee($older->sale_number)
            ->assertSee($settlement->payment_number)
            ->assertDontSee($voided->sale_number)
            ->assertDontSee('PRIVATE PAYMENT NOTE');
    }

    public function test_balances_and_payments_follow_each_roles_scope(): void
    {
        $rep = User::factory()->create(['role' => UserRole::SalesRep]);
        $mine = $this->sale($rep, '1000.00', '250.00', '2026-09-02');
        $theirs = $this->sale($this->admin, '7000.00', '1000.00', '2026-09-01');

        $page = $this->actingAs($rep)->get(route('customers.show', $this->customer))->assertOk();

        $this->assertSame('750.00', $page->viewData('receivables')['outstanding']);
        $this->assertSame([$mine->id], $page->viewData('receivables')['sales']->pluck('id')->all());
        $this->assertSame([$mine->payments()->sole()->id], $page->viewData('payments')->pluck('id')->all());
        $page->assertDontSee($theirs->sale_number)
            ->assertDontSee($theirs->payments()->sole()->payment_number)
            ->assertDontSee('6,000.00');

        // Management sees every seller's balances and payments for the customer.
        foreach ([$this->admin, User::factory()->create(['role' => UserRole::Manager])] as $manager) {
            $page = $this->actingAs($manager)->get(route('customers.show', $this->customer))->assertOk()
                ->assertSee($theirs->sale_number)
                ->assertSee($mine->payments()->sole()->payment_number)
                ->assertSee($theirs->payments()->sole()->payment_number);
            $this->assertSame('6750.00', $page->viewData('receivables')['outstanding']);
            $this->assertSame([$theirs->id, $mine->id], $page->viewData('receivables')['sales']->pluck('id')->all());
        }
    }

    public function test_a_customer_of_another_business_is_not_found(): void
    {
        $this->sale($this->admin, '1000.00', '0.00', '2026-09-01');
        $outsider = User::factory()->forBusiness(Business::factory()->create())->create(['role' => UserRole::Admin]);

        $this->actingAs($outsider)->get(route('customers.show', $this->customer))->assertNotFound();
    }

    public function test_a_customer_with_nothing_owed_or_paid_says_so(): void
    {
        $this->actingAs($this->admin)->get(route('customers.show', $this->customer))->assertOk()
            ->assertSee("Nothing is owed on this customer's sales.", false)
            ->assertSee('No payments recorded yet.')
            ->assertViewHas('receivables', fn (array $receivables): bool => $receivables['outstanding'] === '0.00'
                && $receivables['open_count'] === 0);
    }

    private function sale(User $seller, string $price, string $paid, string $date): Sale
    {
        $product = Product::factory()->create(['selling_price' => $price, 'current_stock' => '10.000']);

        return app(CreateSale::class)->execute($seller, [
            'is_walk_in' => false,
            'customer_id' => $this->customer->id,
            'sale_date' => $date,
            'products' => [['product_id' => $product->id, 'quantity' => '1']],
            'payment_method' => 'cash',
            'amount_paid' => $paid,
        ]);
    }

    private function settle(Sale $sale, string $amount, string $note): SalePayment
    {
        $this->actingAs($this->admin)->startSession();
        $session = app('session')->driver();
        $token = app(IssueSalePaymentRequest::class)->execute($sale->fresh(), $this->admin, $session);

        // A later payment is a distinct moment, so it orders after the initial one.
        Carbon::setTestNow(now()->addMinute());

        try {
            return app(RecordSalePayment::class)->execute($this->admin, $sale->fresh(), [
                'amount' => $amount, 'payment_method' => 'transfer', 'note' => $note, 'request_token' => $token,
            ], $session->getId());
        } finally {
            Carbon::setTestNow();
        }
    }
}
