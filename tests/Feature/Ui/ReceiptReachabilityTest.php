<?php

namespace Tests\Feature\Ui;

use App\Actions\Sale\RecordSaleRefund;
use App\Actions\Sale\RecordSaleReturn;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SalePaymentType;
use App\Enums\UserRole;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\SaleRefund;
use App\Models\SaleRefundRequest;
use App\Models\SaleReturn;
use App\Models\SaleReturnRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Receipt printing already worked, but no record list linked to a receipt, so an operator could
 * only reach one by editing the URL. These tests hold the list -> receipt -> print path open: every
 * receipt must be reachable from the list that owns its record, the link must resolve to a
 * printable receipt, and the sales list must not offer a link the viewer is not allowed to open.
 */
class ReceiptReachabilityTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Builds one fully paid sale that has been partly returned and partly refunded, so all four
     * receipt-bearing records exist for the same sale. Ownership is set at creation because a
     * completed sale is immutable — `sold_by` cannot be reassigned afterwards.
     *
     * @return array{0: User, 1: Sale, 2: SalePayment, 3: SaleReturn, 4: SaleRefund}
     */
    private function fullyExercisedSale(?User $soldBy = null): array
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $product = Product::factory()->create(['current_stock' => '10.000']);

        $sale = Sale::factory()->create([
            'sold_by' => ($soldBy ?? $admin)->id,
            'subtotal' => '100000.00',
            'total_amount' => '100000.00',
            'amount_paid' => '100000.00',
            'balance_due' => '0.00',
            'payment_status' => PaymentStatus::Paid,
        ]);

        $item = new SaleItem;
        foreach ([
            'sale_id' => $sale->id, 'product_id' => $product->id,
            'product_sku_snapshot' => $product->sku, 'product_name_snapshot' => $product->name,
            'unit_snapshot' => $product->unit->value, 'quantity' => '2.000',
            'unit_price' => '50000.00', 'line_total' => '100000.00', 'created_at' => now(),
        ] as $key => $value) {
            $item->$key = $value;
        }
        $item->save();

        $payment = new SalePayment;
        foreach ([
            'payment_number' => 'PMT-'.Str::upper(Str::random(10)), 'sale_id' => $sale->id,
            'customer_id' => $sale->customer_id, 'amount' => '100000.00',
            'payment_method' => PaymentMethod::Cash, 'payment_type' => SalePaymentType::Initial,
            'recorded_by' => $admin->id, 'recorded_by_name_snapshot' => $admin->name,
            'paid_at' => now(), 'cumulative_paid_after' => '100000.00', 'balance_after' => '0.00',
            'payment_status_after' => PaymentStatus::Paid, 'initial_sale_guard' => $sale->id,
        ] as $key => $value) {
            $payment->$key = $value;
        }
        $payment->save();

        $return = app(RecordSaleReturn::class)->execute($admin, $sale, [
            'request_token' => $this->token(SaleReturnRequest::class, $admin, $sale),
            'reason' => 'Customer return',
            'items' => [['sale_item_id' => $item->id, 'quantity' => '1.000', 'disposition' => 'restock']],
        ], 'test');

        $refund = app(RecordSaleRefund::class)->execute($admin, $sale->fresh(), [
            'request_token' => $this->token(SaleRefundRequest::class, $admin, $sale),
            'amount' => '20000.00',
            'payment_method' => 'cash',
            'reason' => 'Approved refund',
        ], 'test');

        return [$admin, $sale, $payment, $return, $refund];
    }

    /** @param class-string $model */
    private function token(string $model, User $actor, Sale $sale): string
    {
        $token = Str::random(64);
        $request = new $model;
        foreach ([
            'token_hash' => hash('sha256', $token), 'sale_id' => $sale->id,
            'actor_id' => $actor->id, 'session_id' => 'test', 'expires_at' => now()->addMinute(),
        ] as $key => $value) {
            $request->$key = $value;
        }
        $request->save();

        return $token;
    }

    public function test_every_receipt_is_reachable_from_the_list_that_owns_its_record(): void
    {
        [$admin, $sale, $payment, $return, $refund] = $this->fullyExercisedSale();

        $expected = [
            'sales.index' => route('sales.receipt', $sale, false),
            'sale-payments.index' => route('sales.payments.receipt', [$sale, $payment], false),
            'returns.index' => route('returns.receipt', $return, false),
            'refunds.index' => route('refunds.receipt', $refund, false),
        ];

        foreach ($expected as $listing => $receiptUrl) {
            $html = $this->actingAs($admin)->get(route($listing))->assertOk()->getContent();

            $this->assertStringContainsString($receiptUrl.'"', $html,
                "{$listing} must link to {$receiptUrl}");
        }
    }

    public function test_each_linked_receipt_resolves_to_a_printable_document(): void
    {
        [$admin, $sale, $payment, $return, $refund] = $this->fullyExercisedSale();

        $receipts = [
            route('sales.receipt', $sale),
            route('sales.payments.receipt', [$sale, $payment]),
            route('returns.receipt', $return),
            route('refunds.receipt', $refund),
        ];

        foreach ($receipts as $url) {
            $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();

            $this->assertStringContainsString('data-print-trigger', $html,
                "{$url} must expose the shared print control");
        }
    }

    public function test_the_sales_list_never_offers_a_receipt_the_viewer_cannot_open(): void
    {
        $rep = User::factory()->create(['role' => UserRole::SalesRep]);
        [, $ownSale] = $this->fullyExercisedSale($rep);
        [, $otherSale] = $this->fullyExercisedSale();

        $html = $this->actingAs($rep)->get(route('sales.index'))->assertOk()->getContent();

        $this->assertStringContainsString(route('sales.receipt', $ownSale, false).'"', $html);
        $this->assertStringNotContainsString(route('sales.receipt', $otherSale, false).'"', $html);
        $this->actingAs($rep)->get(route('sales.receipt', $otherSale))->assertForbidden();
    }

    public function test_the_three_manager_only_lists_stay_closed_to_a_sales_rep(): void
    {
        $rep = User::factory()->create(['role' => UserRole::SalesRep]);

        foreach (['sale-payments.index', 'returns.index', 'refunds.index'] as $listing) {
            $this->actingAs($rep)->get(route($listing))->assertForbidden();
        }
    }
}
