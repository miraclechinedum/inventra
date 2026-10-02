<?php

namespace Tests\Feature\Tenancy;

use App\Actions\Business\ProvisionBusiness;
use App\Actions\Inventory\CreateProduct;
use App\Actions\Staff\CreateStaff;
use App\Enums\UserRole;
use App\Models\Business;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Tenancy\CurrentBusiness;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The last place of each capped allowance under real contention, on the test database only.
 *
 * A second MySQL session plays the competing request: it takes the Business's subscription lock
 * and the last Manager place, Sales Representative place or product inside an open transaction.
 * The first request must wait on that lock rather than count rows that are about to change — and
 * once the competitor commits, it must see the place taken. Both succeeding is impossible.
 */
class StaffSeatConcurrencyTest extends TestCase
{
    private Business $business;

    private User $owner;

    /** A second, independent MySQL session: the competing request. */
    private Connection $competitor;

    protected function setUp(): void
    {
        parent::setUp();

        // Committed rows are needed for a second connection to contend on, so this test rebuilds
        // the schema through the guard, commits its own fixtures and rebuilds again at the end.
        static::assertSafeTestDatabase(DB::connection());
        $this->rebuildTestSchema();
        DB::commit();

        // Provisioned onto the standard plan: 1 Manager, 1 Sales Representative, 20 products.
        app(CurrentBusiness::class)->forget();
        $this->owner = app(ProvisionBusiness::class)->execute([
            'business_name' => 'Seat Ltd', 'owner_name' => 'Seat Owner', 'email' => 'owner@seat.test',
            'phone' => User::normalizePhone('08035550301'), 'password' => 'Secret123',
        ]);
        $this->business = $this->owner->business;

        // Built from the same guarded `mysql` configuration, so every guard check applies to it too.
        $this->competitor = app('db.factory')->make(config('database.connections.mysql'), 'mysql');
        static::assertSafeTestDatabase($this->competitor);
    }

    protected function tearDown(): void
    {
        if ($this->competitor->transactionLevel() > 0) {
            $this->competitor->rollBack();
        }

        $this->competitor->disconnect();
        $this->rebuildTestSchema();

        parent::tearDown();
    }

    public function test_two_requests_for_the_only_manager_place_cannot_both_succeed(): void
    {
        $this->race(
            fn () => $this->competitorHires(UserRole::Manager, 'rival.manager@seat.test'),
            fn () => $this->hire(UserRole::Manager, 'manager@seat.test'),
            'staff',
        );

        $this->assertSame(1, $this->accounts(UserRole::Manager));
    }

    public function test_two_requests_for_the_only_sales_representative_place_cannot_both_succeed(): void
    {
        $this->race(
            fn () => $this->competitorHires(UserRole::SalesRep, 'rival.rep@seat.test'),
            fn () => $this->hire(UserRole::SalesRep, 'rep@seat.test'),
            'staff',
        );

        $this->assertSame(1, $this->accounts(UserRole::SalesRep));
    }

    public function test_two_requests_for_the_twentieth_product_cannot_both_succeed(): void
    {
        $category = app(CurrentBusiness::class)->run($this->business, fn () => ProductCategory::factory()->forBusiness($this->business)->create());
        app(CurrentBusiness::class)->run($this->business, fn () => Product::factory()->count(19)->forBusiness($this->business)->create(['category_id' => $category->id]));

        $this->race(
            fn () => $this->competitor->table('products')->insert([
                'business_id' => $this->business->id, 'public_id' => (string) Str::ulid(), 'category_id' => $category->id, 'name' => 'Rival product', 'sku' => 'RIVAL-20',
                'cost_price' => '1.00', 'selling_price' => '2.00', 'current_stock' => '0.000', 'reorder_level' => '0.000',
                'unit' => 'piece', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]),
            fn () => app(CurrentBusiness::class)->run($this->business, fn () => app(CreateProduct::class)->execute($this->owner, [
                'category_id' => $category->id, 'name' => 'Twentieth product', 'sku' => 'MINE-20', 'description' => null,
                'cost_price' => '1.00', 'selling_price' => '2.00', 'initial_stock' => '0.000', 'reorder_level' => '0.000', 'unit' => 'piece',
            ])),
            'product',
        );

        $this->assertSame(20, DB::table('products')->where('business_id', $this->business->id)->count());
    }

    /**
     * The competitor locks the Business's allowances and takes the last place; the request under
     * test must wait on that lock, then — once the competitor commits — be refused.
     */
    private function race(\Closure $competitorTakesLastPlace, \Closure $attempt, string $errorKey): void
    {
        $this->competitor->beginTransaction();
        $this->competitor->table('business_subscriptions')->where('business_id', $this->business->id)->lockForUpdate()->first();
        $competitorTakesLastPlace();

        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            $attempt();
            $this->fail('The allowance check must wait for the competing request, not count past it.');
        } catch (QueryException $exception) {
            $this->assertSame(1205, (int) ($exception->errorInfo[1] ?? 0), 'a lock wait, nothing else');
        }

        $this->competitor->commit();

        try {
            $attempt();
            $this->fail('The last place is already taken.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($errorKey, $exception->errors());
        }
    }

    private function competitorHires(UserRole $role, string $email): void
    {
        $this->competitor->table('users')->insert([
            'business_id' => $this->business->id, 'name' => 'Rival hire', 'email' => $email, 'password' => bcrypt('Secret123'),
            'role' => $role->value, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function hire(UserRole $role, string $email): void
    {
        app(CurrentBusiness::class)->run($this->business, fn () => app(CreateStaff::class)->execute(
            $this->owner, ['name' => 'Hire', 'email' => $email, 'phone' => null], $role,
        ));
    }

    private function accounts(UserRole $role): int
    {
        return DB::table('users')->where('business_id', $this->business->id)->where('role', $role->value)->count();
    }
}
