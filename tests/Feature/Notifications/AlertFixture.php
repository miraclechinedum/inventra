<?php

namespace Tests\Feature\Notifications;

use App\Actions\Customer\CreateCustomer;
use App\Actions\Inventory\CreateCategory;
use App\Actions\Inventory\CreateProduct;
use App\Actions\Sale\CreateSale;
use App\Enums\UserRole;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Builds the domain conditions the alert module observes, through the real domain Actions. */
class AlertFixture
{
    public function admin(string $name = 'Owner Admin'): User
    {
        return User::factory()->create(['role' => UserRole::Admin, 'name' => $name]);
    }

    public function manager(string $name = 'Floor Manager'): User
    {
        return User::factory()->create(['role' => UserRole::Manager, 'name' => $name]);
    }

    public function rep(string $name = 'Shop Rep'): User
    {
        return User::factory()->create(['role' => UserRole::SalesRep, 'name' => $name]);
    }

    public function product(User $actor, array $overrides = []): Product
    {
        $category = app(CreateCategory::class)->execute($actor, ['name' => 'Cat '.Str::random(5), 'description' => null]);

        return app(CreateProduct::class)->execute($actor, array_merge([
            'category_id' => $category->id,
            'name' => 'Widget',
            'sku' => 'SKU-'.Str::upper(Str::random(6)),
            'description' => null,
            'cost_price' => '1000.00',
            'selling_price' => '2500.00',
            'initial_stock' => '100',
            'reorder_level' => '5',
            'unit' => 'piece',
            'is_active' => true,
        ], $overrides));
    }

    /** A completed Sale with an outstanding balance, built through the real Sale pipeline. */
    public function saleWithBalance(User $actor, string $amountPaid = '0'): Sale
    {
        $customer = app(CreateCustomer::class)->execute($actor, [
            'first_name' => 'Alert', 'last_name' => 'Customer', 'phone' => '0803'.random_int(1000000, 9999999),
            'email' => null, 'address' => null, 'city' => null, 'notes' => null, 'whatsapp_opt_in' => false,
        ]);
        $product = $this->product($actor, ['initial_stock' => '500', 'reorder_level' => '0']);

        return app(CreateSale::class)->execute($actor, [
            'customer_id' => $customer->id,
            'products' => [['product_id' => $product->id, 'quantity' => '2']],
            'payment_method' => 'cash',
            'amount_paid' => $amountPaid,
            'notes' => null,
        ]);
    }

    /**
     * A Sale whose stored aggregates no longer agree with its ledger. The mismatch is made on the
     * ledger side, because the sales row itself is protected by a reconciliation CHECK constraint —
     * which is exactly the invariant this alert exists to notice breaking.
     */
    public function saleWithLedgerMismatch(User $actor): Sale
    {
        $sale = $this->saleWithBalance($actor, '1000.00');

        DB::table('sale_payments')
            ->where('sale_id', $sale->id)
            ->update(['amount' => '900.00']);

        return $sale->fresh();
    }

    public function requestToken(object $request, User $actor, ?Sale $sale = null): string
    {
        $token = Str::random(64);

        foreach (array_filter([
            'token_hash' => hash('sha256', $token), 'sale_id' => $sale?->id, 'actor_id' => $actor->id,
            'session_id' => 'alerts', 'expires_at' => now()->addMinutes(30),
        ], static fn ($value) => $value !== null) as $key => $value) {
            $request->$key = $value;
        }
        $request->save();

        return $token;
    }
}
