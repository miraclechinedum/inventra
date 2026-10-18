<?php

namespace Tests\Feature\Returns;

use App\Actions\Sale\CreateSale;
use App\Actions\Sale\DecideSaleDiscount;
use App\Actions\Sale\IssueReturnRequest;
use App\Actions\Sale\IssueSalePaymentRequest;
use App\Actions\Sale\RecordSalePayment;
use App\Actions\Sale\RecordSaleReturn;
use App\Actions\Sale\RequestSaleDiscount;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleDiscountRequest;
use App\Models\User;
use App\Tenancy\CurrentBusiness;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Settlement transitions racing on one Sale, in two independent processes.
 *
 * Returns, payments and discount approvals all serialise on the Sale's row lock. Whichever commits
 * first, the other either completes against the state it left or is refused cleanly, and the Sale
 * always reconciles: total − returned + credit = paid − refunded + balance.
 */
class SettlementConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private const REFUSED = 3;

    private User $admin;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('The pcntl extension is required for the independent-process concurrency probe.');
        }

        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->manager = User::factory()->create(['role' => UserRole::Manager]);
    }

    private function sale(string $paid): Sale
    {
        $product = Product::factory()->create(['selling_price' => '500.00', 'current_stock' => '10.000']);

        return app(CreateSale::class)->execute($this->admin, [
            'is_walk_in' => false, 'customer_id' => Customer::factory()->create(['is_active' => true])->id,
            'sale_date' => CarbonImmutable::now(config('business.timezone'))->toDateString(),
            'products' => [['product_id' => $product->id, 'quantity' => '2']],
            'payment_method' => 'cash', 'amount_paid' => $paid,
        ]);
    }

    private function otherSession(): Store
    {
        return new Store('test', new ArraySessionHandler(1), Str::random(40));
    }

    public function test_a_return_and_a_payment_racing_on_one_sale_always_reconcile(): void
    {
        $sale = $this->sale('0.00');
        $item = $sale->items()->sole();
        $returnSession = $this->otherSession();
        $paymentSession = $this->otherSession();
        $returnToken = app(IssueReturnRequest::class)->execute($this->admin, $sale, $returnSession);
        $paymentToken = app(IssueSalePaymentRequest::class)->execute($sale, $this->admin, $paymentSession);
        DB::commit();

        $outcomes = $this->race([
            fn () => app(RecordSaleReturn::class)->execute(User::findOrFail($this->admin->id), Sale::query()->findOrFail($sale->id), [
                'request_token' => $returnToken, 'reason' => 'Returned in full',
                'items' => [['sale_item_id' => $item->id, 'quantity' => '2', 'disposition' => 'restock']],
            ], $returnSession->getId()),
            fn () => app(RecordSalePayment::class)->execute(User::findOrFail($this->admin->id), Sale::query()->findOrFail($sale->id), [
                'request_token' => $paymentToken, 'amount' => '1000.00', 'payment_method' => 'cash', 'note' => null,
            ], $paymentSession->getId()),
        ]);

        $this->assertSame(0, $outcomes[0], 'the return always completes');
        $this->assertContains($outcomes[1], [0, self::REFUSED], 'the payment completes first or is refused as overpayment');

        $row = DB::table('sales')->where('id', $sale->id)->first();
        $this->assertSame('1000.00', $row->returned_amount);
        $this->assertSame('0.00', $row->balance_due);
        $this->assertSame($outcomes[1] === 0 ? '1000.00' : '0.00', $row->refundable_credit, 'credit is exactly what was paid');
        $this->assertReconciles($row);
        $this->rebuildTestSchema();
    }

    public function test_a_discount_approval_and_a_return_racing_on_one_sale_never_overcredit(): void
    {
        $sale = $this->sale('1000.00');
        $item = $sale->items()->sole();
        $request = app(RequestSaleDiscount::class)->execute($this->manager, $sale, ['amount' => '100.00', 'reason' => 'Agreed goodwill on the order.']);
        $returnSession = $this->otherSession();
        $returnToken = app(IssueReturnRequest::class)->execute($this->admin, $sale, $returnSession);
        DB::commit();

        $outcomes = $this->race([
            fn () => app(DecideSaleDiscount::class)->approve(User::findOrFail($this->admin->id), SaleDiscountRequest::query()->findOrFail($request->id)),
            fn () => app(RecordSaleReturn::class)->execute(User::findOrFail($this->admin->id), Sale::query()->findOrFail($sale->id), [
                'request_token' => $returnToken, 'reason' => 'One came back',
                'items' => [['sale_item_id' => $item->id, 'quantity' => '1', 'disposition' => 'restock']],
            ], $returnSession->getId()),
        ]);

        $this->assertContains($outcomes[0], [0, self::REFUSED], 'the approval lands first or is refused once a return exists');
        $this->assertSame(0, $outcomes[1], 'the return always completes');

        $row = DB::table('sales')->where('id', $sale->id)->first();
        $value = DB::table('sale_returns')->where('sale_id', $sale->id)->value('merchandise_value');
        // Approved first: the return is valued net (₦450). Refused: no discount, valued gross (₦500).
        $this->assertSame($outcomes[0] === 0 ? '450.00' : '500.00', $value);
        $this->assertSame($outcomes[0] === 0 ? '100.00' : '0.00', $row->discount_amount);
        $this->assertReconciles($row);
        $this->rebuildTestSchema();
    }

    /**
     * Runs each callable in its own process, released together, and returns their exit codes: 0 for
     * success, REFUSED for a clean validation refusal, anything else for an unexpected failure.
     *
     * @param  list<callable>  $work
     * @return list<int>
     */
    private function race(array $work): array
    {
        $barrier = tempnam(sys_get_temp_dir(), 'inventra-race-');
        unlink($barrier);
        $business = app(CurrentBusiness::class)->get();
        $pids = [];

        try {
            foreach ($work as $callable) {
                $pid = pcntl_fork();
                if ($pid === 0) {
                    while (! file_exists($barrier)) {
                        usleep(500);
                    }

                    try {
                        DB::purge();
                        app(CurrentBusiness::class)->run($business, $callable);
                        exit(0);
                    } catch (ValidationException) {
                        exit(self::REFUSED);
                    } catch (\Throwable) {
                        exit(1);
                    }
                }

                $this->assertGreaterThan(0, $pid);
                $pids[] = $pid;
            }

            touch($barrier);
            $codes = [];
            foreach ($pids as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertTrue(pcntl_wifexited($status));
                $codes[] = pcntl_wexitstatus($status);
            }

            DB::purge();

            return $codes;
        } finally {
            if (file_exists($barrier)) {
                unlink($barrier);
            }
        }
    }

    private function assertReconciles(object $row): void
    {
        $left = bcadd(bcsub($row->total_amount, $row->returned_amount, 2), $row->refundable_credit, 2);
        $right = bcadd(bcsub($row->amount_paid, $row->refunded_amount, 2), $row->balance_due, 2);
        $this->assertSame(0, bccomp($left, $right, 2), "the Sale does not reconcile: {$left} != {$right}");
    }
}
