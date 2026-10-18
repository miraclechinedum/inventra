<?php

namespace Tests\Feature\Sale;

use App\Actions\Sale\IssueSaleRequest;
use App\Actions\Sale\RecordSaleSubmission;
use App\Enums\InventoryMovementType;
use App\Enums\UserRole;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleRequest;
use App\Models\User;
use App\Models\WhatsAppAutomation;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\SubmitsSales;
use Tests\TestCase;

/**
 * Record Sale submissions are single-use.
 *
 * However a submission is delivered twice — a retry, a double click, the back button, two
 * processes at once — it records one Sale and deducts stock once, and the repeat is handed that
 * same Sale.
 */
class SaleSubmissionIdempotencyTest extends TestCase
{
    use RefreshDatabase, SubmitsSales;

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

        $this->rep = User::factory()->create(['role' => UserRole::SalesRep]);
        $this->customer = Customer::factory()->create(['is_active' => true]);
        $this->product = Product::factory()->create(['selling_price' => '1000.00', 'current_stock' => '20.000']);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'is_walk_in' => '0',
            'customer_id' => $this->customer->id,
            'sale_date' => CarbonImmutable::now(config('business.timezone'))->toDateString(),
            'products' => [['product_id' => $this->product->id, 'quantity' => '2']],
            'amount_paid' => '2000.00',
        ], $overrides);
    }

    public function test_the_page_issues_a_hashed_token_bound_to_operator_session_and_business(): void
    {
        $page = $this->actingAs($this->rep)->get(route('sales.create'))->assertOk();
        $token = $page->viewData('requestToken');

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{64}$/', $token);
        $page->assertSee('name="request_token" value="'.$token.'"', false);

        $row = SaleRequest::query()->sole();
        $this->assertSame(hash('sha256', $token), $row->token_hash);
        $this->assertSame($this->rep->id, (int) $row->actor_id);
        $this->assertSame($this->rep->business_id, (int) $row->business_id);
        $this->assertNotEmpty($row->session_id);
        $this->assertNull($row->used_at);
        $this->assertEqualsWithDelta(now()->addHours(12)->getTimestamp(), $row->expires_at->getTimestamp(), 5);
        $this->assertStringNotContainsString($token, DB::table('sale_requests')->get()->toJson(), 'only the hash is stored');
    }

    public function test_a_repeated_submission_records_one_sale_and_moves_stock_once(): void
    {
        $this->actingAs($this->rep);
        $payload = $this->withSaleToken($this->payload());

        $first = $this->post(route('sales.store'), $payload)->assertRedirect(route('sales.create'));
        $second = $this->post(route('sales.store'), $payload)->assertRedirect(route('sales.create'));

        $sale = Sale::query()->sole();
        $first->assertSessionHas('completedSale', $sale->id);
        $second->assertSessionHas('completedSale', $sale->id);

        $this->assertSame('18.000', $this->product->fresh()->current_stock);
        $this->assertSame(1, DB::table('sale_items')->count());
        $this->assertSame(1, DB::table('sale_payments')->count());
        $this->assertSame(1, DB::table('inventory_movements')->where('type', InventoryMovementType::Sale->value)->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'sale_created')->count());

        // The submission and the Sale name each other.
        $submission = SaleRequest::query()->sole();
        $this->assertNotNull($submission->used_at);
        $this->assertSame($sale->id, (int) $submission->sale_id);
        $this->assertSame($submission->id, (int) $sale->sale_request_id);
    }

    public function test_a_spent_token_never_records_a_different_sale(): void
    {
        $this->actingAs($this->rep);
        $payload = $this->withSaleToken($this->payload());
        $this->post(route('sales.store'), $payload)->assertSessionHasNoErrors();

        $this->post(route('sales.store'), array_merge($payload, ['products' => [['product_id' => $this->product->id, 'quantity' => '5']]]))
            ->assertSessionHasErrors(['request_token' => 'This sale form was already used to record a different sale. Review the sale and submit it again.']);

        $this->assertSame(1, Sale::query()->count());
        $this->assertSame('18.000', $this->product->fresh()->current_stock);
    }

    public function test_a_split_or_reordered_cart_is_the_same_submission(): void
    {
        $other = Product::factory()->create(['selling_price' => '500.00', 'current_stock' => '20.000']);
        $this->actingAs($this->rep);
        $payload = $this->withSaleToken($this->payload([
            'products' => [['product_id' => $this->product->id, 'quantity' => '2'], ['product_id' => $other->id, 'quantity' => '1']],
            'amount_paid' => '2500.00',
        ]));
        $this->post(route('sales.store'), $payload)->assertSessionHasNoErrors();

        // The same goods, in another order and with a line split in two.
        $this->post(route('sales.store'), array_merge($payload, ['products' => [
            ['product_id' => $other->id, 'quantity' => '1'],
            ['product_id' => $this->product->id, 'quantity' => '1'],
            ['product_id' => $this->product->id, 'quantity' => '1'],
        ]]))->assertSessionHasNoErrors()->assertRedirect(route('sales.create'))
            ->assertSessionHas('completedSale', Sale::query()->sole()->id);

        $this->assertSame(1, Sale::query()->count());
    }

    public function test_a_missing_foreign_or_expired_token_is_refused_and_writes_nothing(): void
    {
        $this->actingAs($this->rep);

        $this->post(route('sales.store'), $this->payload())->assertSessionHasErrors('request_token');
        $this->post(route('sales.store'), $this->payload(['request_token' => Str::random(64)]))->assertSessionHasErrors('request_token');

        // Another operator's token, and this operator's token from another session.
        $colleague = User::factory()->create(['role' => UserRole::SalesRep]);
        $theirs = app(IssueSaleRequest::class)->execute($colleague, $this->otherSession());
        $this->saleToken();
        $this->post(route('sales.store'), $this->payload(['request_token' => $theirs]))->assertSessionHasErrors('request_token');
        $elsewhere = app(IssueSaleRequest::class)->execute($this->rep, $this->otherSession());
        $this->post(route('sales.store'), $this->payload(['request_token' => $elsewhere]))->assertSessionHasErrors('request_token');

        // A page left open past its lifetime.
        $expired = $this->withSaleToken($this->payload());
        $this->travel(SaleRequest::LIFETIME_HOURS)->hours();
        $this->travel(1)->minutes();
        $this->post(route('sales.store'), $expired)
            ->assertSessionHasErrors(['request_token' => 'This sale form has expired. Review the sale and submit it again.']);

        $this->assertSame(0, Sale::query()->count());
        $this->assertSame('20.000', $this->product->fresh()->current_stock);
    }

    public function test_an_expired_form_comes_back_restored_with_a_fresh_token(): void
    {
        $this->actingAs($this->rep);
        $expired = $this->withSaleToken($this->payload(['sale_date' => CarbonImmutable::now(config('business.timezone'))->subDay()->toDateString()]));
        $this->travel(13)->hours();

        $this->from(route('sales.create'))->post(route('sales.store'), $expired)
            ->assertRedirect(route('sales.create'))->assertSessionHasErrors('request_token');

        $page = $this->get(route('sales.create'))->assertOk();
        $this->assertSame($this->product->id, $page->viewData('restored')['lines'][0]['product']['id']);
        $this->assertNotSame($expired['request_token'], $page->viewData('requestToken'));
    }

    public function test_a_rejected_sale_leaves_its_token_unspent_for_the_corrected_submission(): void
    {
        $this->actingAs($this->rep);
        $payload = $this->withSaleToken($this->payload(['products' => [['product_id' => $this->product->id, 'quantity' => '25']]]));

        $this->post(route('sales.store'), $payload)->assertSessionHasErrors('products');
        $this->assertNull(SaleRequest::query()->sole()->used_at);

        $this->post(route('sales.store'), array_merge($payload, ['products' => [['product_id' => $this->product->id, 'quantity' => '2']]]))
            ->assertSessionHasNoErrors();
        $this->assertSame(1, Sale::query()->count());
        $this->assertSame('18.000', $this->product->fresh()->current_stock);
    }

    public function test_another_business_cannot_spend_a_token(): void
    {
        $this->actingAs($this->rep);
        $payload = $this->withSaleToken($this->payload());

        $outsider = User::factory()->forBusiness(Business::factory()->create())->create(['role' => UserRole::Admin]);
        $this->actingAs($outsider)->post(route('sales.store'), ['request_token' => $payload['request_token']] + $this->payload([
            'customer_id' => Customer::factory()->forBusiness($outsider->business)->create()->id,
            'products' => [['product_id' => Product::factory()->forBusiness($outsider->business)->create(['current_stock' => '5'])->id, 'quantity' => '1']],
            'amount_paid' => '0.00',
        ]))->assertSessionHasErrors('request_token');

        $this->assertSame(0, DB::table('sales')->count());
    }

    public function test_a_replay_does_not_queue_a_second_whatsapp_message(): void
    {
        WhatsAppConnection::query()->firstOrCreate(['singleton_key' => 'whatsapp'])->forceFill([
            'provider' => 'fake', 'waba_id' => '100000000000001', 'phone_number_id' => '200000000000001',
            'display_phone_number' => '+234 700 000 1234', 'phone_number' => '+2347000001234',
            'access_token' => 'fake-business-token', 'status' => 'connected',
            'verified_at' => now(), 'connected_at' => now(),
        ])->save();
        WhatsAppAutomation::forKey(WhatsAppAutomation::POST_PURCHASE)->update([
            'enabled' => true, 'template_name' => 'inventra_post_purchase',
            'template_language' => 'en', 'template_status' => 'APPROVED',
        ]);
        $this->customer->forceFill([
            'phone' => '+2348090000001', 'whatsapp_opt_in' => true, 'whatsapp_opt_in_at' => now(), 'whatsapp_opt_out_at' => null,
        ])->save();

        $this->actingAs($this->rep);
        $payload = $this->withSaleToken($this->payload());
        $this->post(route('sales.store'), $payload)->assertSessionHasNoErrors();
        $this->post(route('sales.store'), $payload)->assertSessionHasNoErrors()->assertRedirect(route('sales.create'))
            ->assertSessionHas('completedSale', Sale::query()->sole()->id);

        $this->assertSame(1, WhatsAppMessage::query()->where('type', 'post_purchase')->count());
    }

    public function test_the_database_refuses_a_spent_submission_without_its_sale(): void
    {
        app(IssueSaleRequest::class)->execute($this->rep, $this->otherSession());

        $this->expectException(QueryException::class);
        DB::table('sale_requests')->update(['used_at' => now(), 'payload_hash' => str_repeat('a', 64)]);
    }

    /** A recorded Sale and the submission that recorded it can each outlive nothing of the other. */
    public function test_the_database_keeps_a_sale_and_its_submission_together(): void
    {
        $this->actingAs($this->rep);
        $this->post(route('sales.store'), $this->withSaleToken($this->payload()))->assertSessionHasNoErrors();
        $sale = Sale::query()->sole();
        $submission = SaleRequest::query()->sole();

        foreach ([
            'the submission' => fn () => DB::table('sale_requests')->where('id', $submission->id)->delete(),
            'the sale' => fn () => DB::table('sales')->where('id', $sale->id)->delete(),
        ] as $what => $delete) {
            try {
                DB::transaction($delete);
                $this->fail("The database deleted {$what} while the other still names it.");
            } catch (QueryException) {
                $this->assertTrue(DB::table('sales')->where('id', $sale->id)->exists());
                $this->assertTrue(DB::table('sale_requests')->where('id', $submission->id)->exists());
            }
        }

        // And a submission cannot be pointed at another Business's Sale.
        $this->expectException(QueryException::class);
        DB::table('sale_requests')->where('id', $submission->id)->update(['business_id' => Business::factory()->create()->id]);
    }

    public function test_pruning_removes_only_expired_unused_submissions(): void
    {
        $this->actingAs($this->rep);
        $spent = $this->withSaleToken($this->payload());
        $this->post(route('sales.store'), $spent)->assertSessionHasNoErrors();
        $stale = $this->saleToken();
        $this->travel(13)->hours();
        $fresh = $this->saleToken();

        $this->artisan('model:prune', ['--model' => SaleRequest::class])->assertSuccessful();

        $hashes = SaleRequest::query()->pluck('token_hash')->all();
        $this->assertContains(hash('sha256', $spent['request_token']), $hashes, 'a used submission is evidence');
        $this->assertContains(hash('sha256', $fresh), $hashes);
        $this->assertNotContains(hash('sha256', $stale), $hashes);
    }

    /** Two processes deliver the same submission at once: one Sale, one stock movement. */
    public function test_concurrent_deliveries_of_one_submission_record_one_sale(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('The pcntl extension is required for the independent-process concurrency probe.');
        }

        $session = $this->otherSession();
        $token = app(IssueSaleRequest::class)->execute($this->rep, $session);
        $payload = array_merge($this->payload(), ['request_token' => $token, 'payment_method' => 'cash']);
        DB::commit();

        $barrier = tempnam(sys_get_temp_dir(), 'inventra-sale-');
        unlink($barrier);

        try {
            $processes = [];
            foreach ([1, 2] as $child) {
                $pid = pcntl_fork();
                if ($pid === 0) {
                    while (! file_exists($barrier)) {
                        usleep(1_000);
                    }

                    try {
                        DB::purge();
                        $actor = User::findOrFail($this->rep->id);
                        app(RecordSaleSubmission::class)->execute($actor, $payload, $session->getId());
                        exit(0);
                    } catch (\Throwable) {
                        exit(1);
                    }
                }

                $this->assertGreaterThan(0, $pid);
                $processes[] = $pid;
            }

            touch($barrier);
            foreach ($processes as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertTrue(pcntl_wifexited($status));
                $this->assertSame(0, pcntl_wexitstatus($status), 'both deliveries succeed: one records, one replays');
            }

            DB::purge();
            $this->assertSame(1, DB::table('sales')->count());
            $this->assertSame('18.000', Product::findOrFail($this->product->id)->current_stock);
            $this->assertSame(1, DB::table('inventory_movements')->where('type', InventoryMovementType::Sale->value)->count());
        } finally {
            if (file_exists($barrier)) {
                unlink($barrier);
            }

            $this->rebuildTestSchema();
        }
    }

    /** A session other than the one this test's requests carry. */
    private function otherSession(): Store
    {
        return new Store('test', new ArraySessionHandler(1), Str::random(40));
    }
}
