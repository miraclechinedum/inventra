<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\UnwindsTenancyMigrations;
use Tests\TestCase;

/**
 * The catalogue and party ownership migrations, run backwards and forwards over real rows on the test
 * database only: existing data is attached deterministically, inconsistent data stops the contract,
 * and a rollback that would collapse valid multi-business data refuses instead.
 */
class DomainOwnershipMigrationTest extends TestCase
{
    use DatabaseMigrations, UnwindsTenancyMigrations;

    /** @var array<string, object> */
    private array $migrations = [];

    protected function setUp(): void
    {
        parent::setUp();

        // These tests run migration down() and up() directly, so the live connection is re-proven first.
        static::assertSafeTestDatabase(DB::connection());

        foreach ([
            'users' => '2026_09_29_040000_enforce_business_ownership_of_users',
            'catalogOwnership' => '2026_09_29_050000_add_business_ownership_to_catalog',
            'catalogTenancy' => '2026_09_29_060000_enforce_catalog_tenancy',
            'partyOwnership' => '2026_09_29_070000_add_business_ownership_to_parties',
            'partyTenancy' => '2026_09_29_080000_enforce_party_tenancy',
        ] as $key => $name) {
            $this->migrations[$key] = require database_path("migrations/{$name}.php");
        }
    }

    public function test_existing_rows_are_owned_by_the_sole_business_and_nothing_is_lost(): void
    {
        try {
            // The transaction tables reference the catalogue and parties, so they are unwound first.
            $this->unwindTenancyFrom('2026_09_29_090000_add_business_ownership_to_sales');
            $this->down('partyTenancy', 'partyOwnership', 'catalogTenancy', 'catalogOwnership');
            // Users were contracted earlier and stay that way here, so the legacy user already has one.
            $userId = DB::table('users')->insertGetId($this->user('legacy@example.com') + ['business_id' => DB::table('businesses')->value('id')]);
            $categoryId = DB::table('product_categories')->insertGetId(['name' => 'Legacy Filters', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            $productId = DB::table('products')->insertGetId($this->product($categoryId, 'LEG-1'));
            DB::table('inventory_movements')->insert($this->movement($productId));
            DB::table('customers')->insert(['customer_code' => 'CUST-LEGACY', 'first_name' => 'Legacy', 'phone' => '+2348030000001', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('suppliers')->insert(['supplier_code' => 'SUP-LEGACY', 'name' => 'Legacy Supply', 'is_active' => true, 'created_by' => $userId, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('expense_categories')->insert(['category_code' => 'EXPCAT-LEGACY', 'name' => 'Legacy Rent', 'is_active' => true, 'created_by' => $userId, 'created_at' => now(), 'updated_at' => now()]);
            $counts = $this->counts();

            $this->up('catalogOwnership', 'catalogTenancy', 'partyOwnership', 'partyTenancy');

            $business = DB::table('businesses')->sole()->id;
            $this->assertSame($counts, $this->counts(), 'No row may be added or lost');

            foreach (array_keys($counts) as $table) {
                $this->assertSame(0, DB::table($table)->where('business_id', '<>', $business)->count(), "{$table} rows belong to the sole business");
            }

            // Movements took their Product's Business rather than a constant.
            $this->assertSame(0, DB::table('inventory_movements as m')->join('products as p', 'p.id', '=', 'm.product_id')->whereColumn('m.business_id', '<>', 'p.business_id')->count());

            foreach (['product_categories_business_id_name_unique', 'products_business_id_sku_unique', 'customers_business_id_phone_unique',
                'customers_business_id_customer_code_unique', 'suppliers_business_id_supplier_code_unique', 'expense_categories_business_id_category_code_unique'] as $index) {
                $this->assertTrue($this->indexExists($index), "missing {$index}");
            }

            foreach (['product_categories_name_unique', 'products_sku_unique', 'customers_phone_unique', 'customers_customer_code_unique', 'suppliers_supplier_code_unique', 'expense_categories_category_code_unique'] as $index) {
                $this->assertFalse($this->indexExists($index), "{$index} must be replaced by its per-business form");
            }
        } finally {
            // Real history now exists, which the older migrations' own rollbacks rightly refuse to drop.
            $this->rebuildTestSchema();
        }
    }

    public function test_root_backfill_refuses_to_guess_between_businesses(): void
    {
        try {
            $this->unwindTenancyFrom('2026_09_29_090000_add_business_ownership_to_sales');
            $this->down('partyTenancy', 'partyOwnership', 'catalogTenancy', 'catalogOwnership');
            DB::table('product_categories')->insert(['name' => 'Unowned', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            $this->secondBusiness();

            try {
                $this->migrations['catalogOwnership']->up();
                $this->fail('Unowned rows must not be assigned while more than one business exists.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('exactly one business', $exception->getMessage());
            }

            $this->assertSame(0, DB::table('product_categories')->whereNotNull('business_id')->count(), 'Nothing may be assigned by a guess');
        } finally {
            $this->rebuildTestSchema();
        }
    }

    public function test_the_contract_refuses_a_movement_whose_business_disagrees_with_its_product(): void
    {
        try {
            $business = DB::table('businesses')->value('id');
            $categoryId = DB::table('product_categories')->insertGetId(['business_id' => $business, 'name' => 'Filters', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            $productId = DB::table('products')->insertGetId($this->product($categoryId, 'MIS-1') + ['business_id' => $business]);
            $movementId = DB::table('inventory_movements')->insertGetId($this->movement($productId) + ['business_id' => $business]);

            $this->unwindTenancyFrom('2026_09_29_090000_add_business_ownership_to_sales');
            $this->migrations['catalogTenancy']->down();
            DB::table('inventory_movements')->where('id', $movementId)->update(['business_id' => $this->secondBusiness()]);

            try {
                $this->migrations['catalogTenancy']->up();
                $this->fail('A cross-business movement must stop the contract before any key is added.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('Movements reference products of another business', $exception->getMessage());
            }
        } finally {
            $this->rebuildTestSchema();
        }
    }

    public function test_rollback_refuses_when_two_businesses_share_a_tenant_scoped_value(): void
    {
        try {
            $first = DB::table('businesses')->value('id');
            $second = $this->secondBusiness();

            foreach ([$first, $second] as $business) {
                $category = DB::table('product_categories')->insertGetId(['business_id' => $business, 'name' => 'Shared', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('products')->insert($this->product($category, 'SHARED-SKU') + ['business_id' => $business]);
                DB::table('customers')->insert(['business_id' => $business, 'customer_code' => 'CUST-SHARED', 'first_name' => 'Twin', 'phone' => '+2348030000009', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            }

            foreach (['catalogTenancy' => 'share a category name', 'partyTenancy' => 'global uniqueness cannot be restored'] as $migration => $reason) {
                try {
                    $this->migrations[$migration]->down();
                    $this->fail("{$migration} must refuse to collapse per-business values into global uniqueness.");
                } catch (RuntimeException $exception) {
                    $this->assertStringContainsString($reason, $exception->getMessage());
                }
            }

            // Refused before touching the schema: the per-business constraints are all still there.
            $this->assertTrue($this->indexExists('products_business_id_sku_unique'));
            $this->assertTrue($this->indexExists('customers_business_id_phone_unique'));
            $this->assertSame(2, DB::table('products')->where('sku', 'SHARED-SKU')->count());
        } finally {
            $this->rebuildTestSchema();
        }
    }

    public function test_the_database_rejects_rows_without_a_business(): void
    {
        $business = DB::table('businesses')->value('id');
        $category = DB::table('product_categories')->insertGetId(['business_id' => $business, 'name' => 'Owned', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);

        foreach ([
            'product_categories' => ['name' => 'Orphan', 'is_active' => true],
            'products' => $this->product($category, 'ORPHAN-1'),
            'customers' => ['customer_code' => 'CUST-ORPHAN', 'first_name' => 'Orphan', 'phone' => '+2348030000002', 'is_active' => true],
            'suppliers' => ['supplier_code' => 'SUP-ORPHAN', 'name' => 'Orphan', 'is_active' => true],
            'expense_categories' => ['category_code' => 'EXPCAT-ORPHAN', 'name' => 'Orphan', 'is_active' => true],
        ] as $table => $row) {
            try {
                DB::table($table)->insert($row + ['created_at' => now(), 'updated_at' => now()]);
                $this->fail("{$table} must reject a row without a business.");
            } catch (QueryException) {
                $this->assertSame(0, DB::table($table)->whereNull('business_id')->count());
            }
        }
    }

    public function test_the_users_contract_refuses_while_a_user_has_no_business(): void
    {
        try {
            $this->unwindTenancyFrom('2026_09_29_090000_add_business_ownership_to_sales');
            $this->migrations['users']->down();
            DB::table('users')->insert($this->user('stray@example.com'));

            try {
                $this->migrations['users']->up();
                $this->fail('Ownership cannot be enforced while a user has no business.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('Every user must belong to a business', $exception->getMessage());
            }
        } finally {
            $this->rebuildTestSchema();
        }
    }

    private function down(string ...$keys): void
    {
        foreach ($keys as $key) {
            $this->migrations[$key]->down();
        }
    }

    private function up(string ...$keys): void
    {
        foreach ($keys as $key) {
            $this->migrations[$key]->up();
        }
    }

    private function secondBusiness(): int
    {
        return DB::table('businesses')->insertGetId(['name' => 'Second Business', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        return collect(['product_categories', 'products', 'inventory_movements', 'customers', 'suppliers', 'expense_categories'])
            ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])
            ->all();
    }

    private function indexExists(string $index): bool
    {
        return DB::selectOne(
            'SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND INDEX_NAME = ? LIMIT 1',
            [$index]
        ) !== null;
    }

    private function product(int $categoryId, string $sku): array
    {
        return [
            'public_id' => (string) Str::ulid(), 'category_id' => $categoryId, 'sku' => $sku, 'name' => 'Product '.$sku,
            'unit' => 'piece', 'cost_price' => '1.00', 'selling_price' => '2.00', 'current_stock' => '5.000',
            'reorder_level' => '1.000', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ];
    }

    private function movement(int $productId): array
    {
        return [
            'product_id' => $productId, 'type' => 'initial', 'quantity_change' => '5.000',
            'quantity_before' => '0.000', 'quantity_after' => '5.000', 'created_at' => now(),
        ];
    }

    private function user(string $email): array
    {
        return [
            'name' => 'Legacy', 'email' => $email, 'password' => bcrypt('Password123'), 'role' => 'manager', 'status' => 'active',
            'force_password_change' => false, 'quick_pin_setup_completed' => true, 'created_at' => now(), 'updated_at' => now(),
        ];
    }
}
