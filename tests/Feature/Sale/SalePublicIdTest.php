<?php

namespace Tests\Feature\Sale;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

/**
 * Sales are addressed publicly by a ULID so a URL no longer says how many sales the business has
 * made, nor lets one be guessed from another. `sales.id` is unchanged and still carries every
 * foreign key, join and report.
 */
class SalePublicIdTest extends TestCase
{
    use RefreshDatabase;

    private function sale(?User $seller = null): Sale
    {
        return Sale::factory()->create([
            'sold_by' => ($seller ?? User::factory()->create(['role' => UserRole::Admin]))->id,
            'customer_id' => Customer::factory()->create()->id,
        ]);
    }

    public function test_every_sale_is_given_an_immutable_ulid(): void
    {
        $sale = $this->sale();

        $this->assertNotNull($sale->public_id);
        $this->assertSame(26, mb_strlen($sale->public_id));
        $this->assertTrue(Str::isUlid($sale->public_id));

        // A shared link must keep resolving, so the identifier is fixed at creation.
        $this->expectException(LogicException::class);
        $sale->forceFill(['public_id' => (string) Str::ulid()])->save();
    }

    public function test_sales_are_unique_and_not_sequential(): void
    {
        $ids = collect(range(1, 5))->map(fn (): string => $this->sale()->public_id);

        $this->assertCount(5, $ids->unique(), 'Every Sale needs its own public identifier.');
        // Numeric ids stay sequential internally; the public ones must not be derivable from them.
        $this->assertEmpty($ids->filter(fn (string $id): bool => ctype_digit($id)));
    }

    public function test_sale_routes_are_generated_and_resolved_by_public_id(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $sale = $this->sale($admin);

        foreach (['sales.show', 'sales.receipt', 'sales.receipt.pdf', 'sales.activity', 'sales.corrections.index'] as $name) {
            $url = route($name, $sale);
            $this->assertStringContainsString($sale->public_id, $url, "{$name} must address the Sale by its ULID.");
            $this->assertStringNotContainsString('/sales/'.$sale->id.'/', $url.'/');
        }

        // And the binding resolves that identifier back to the same Sale.
        $this->actingAs($admin)->get(route('sales.show', $sale))->assertOk();
    }

    public function test_a_numeric_id_no_longer_addresses_a_sale(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $sale = $this->sale($admin);

        $this->actingAs($admin)->get('/sales/'.$sale->id)->assertNotFound();
        $this->actingAs($admin)->get('/sales/'.Str::ulid())->assertNotFound();
    }

    /** The internal key is what every relationship still uses. */
    public function test_internal_relationships_still_use_the_numeric_key(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = Customer::factory()->create(['is_active' => true]);
        $product = Product::factory()->create(['selling_price' => '1000.00', 'current_stock' => '10.000']);

        $this->actingAs($admin)->post(route('sales.store'), [
            'is_walk_in' => '0',
            'customer_id' => $customer->id,
            'sale_date' => now(config('business.timezone'))->toDateString(),
            'products' => [['product_id' => $product->id, 'quantity' => '2']],
            'amount_paid' => '2000.00',
        ])->assertRedirect();

        $sale = Sale::query()->sole();

        $this->assertSame($sale->id, $sale->items()->first()->sale_id);
        $this->assertSame($sale->id, $sale->payments()->first()->sale_id);
        $this->assertIsInt($sale->id);
    }
}
