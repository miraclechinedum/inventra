<?php

namespace Tests\Feature\Tenancy;

use App\Models\Business;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\BuildsTransactionWorld;
use Tests\TestCase;

/**
 * The transaction ownership migrations over real history, on the test database only.
 *
 * Two businesses trade through the real actions; their documents' ownership is then erased and the
 * migrations are asked to derive it again from what owns each row. The result must match exactly —
 * sales from their customer or, for walk-ins, their seller; every child from its parent — with not
 * a row, amount, number or stock level changed, and every disagreement refused rather than guessed.
 */
class TransactionOwnershipMigrationTest extends TestCase
{
    use BuildsTransactionWorld;

    private const DOCUMENTS = [
        'sales', 'sale_drafts', 'sale_corrections', 'sale_items', 'sale_payments', 'sale_discount_requests',
        'sale_returns', 'sale_return_items', 'sale_refunds', 'purchases', 'purchase_items', 'expenses',
    ];

    /** @var array<string, object> */
    private array $migrations = [];

    private Business $a;

    private Business $b;

    /** @var array<string, mixed> */
    private array $worldA;

    /** @var array<string, mixed> */
    private array $worldB;

    protected function setUp(): void
    {
        parent::setUp();

        // These tests run migration down() and up() directly, so the live connection is re-proven first.
        static::assertSafeTestDatabase(DB::connection());
        // Each test starts and ends on a freshly migrated, empty schema (rebuilt through the guard),
        // so tests that follow never meet a rolled-back database.
        $this->rebuildTestSchema();

        foreach ([
            'sales' => '2026_09_29_090000_add_business_ownership_to_sales',
            'salesTenancy' => '2026_09_29_100000_enforce_sales_tenancy',
            'children' => '2026_09_29_110000_add_business_ownership_to_sale_children',
            'childrenTenancy' => '2026_09_29_120000_enforce_sale_children_tenancy',
            'purchasing' => '2026_09_29_130000_add_business_ownership_to_purchasing',
            'purchasingTenancy' => '2026_09_29_140000_enforce_purchasing_tenancy',
            'expenses' => '2026_09_29_150000_add_business_ownership_to_expenses',
            'expensesTenancy' => '2026_09_29_160000_enforce_expense_tenancy',
            // Later contracts whose composite keys reference these documents' business keys.
            'whatsappTenancy' => '2026_09_29_222000_enforce_whatsapp_tenancy',
            'tokensTenancy' => '2026_09_29_224000_enforce_request_token_tenancy',
        ] as $key => $name) {
            $this->migrations[$key] = require database_path("migrations/{$name}.php");
        }

        $this->a = Business::query()->orderBy('id')->firstOrFail();
        $this->b = Business::factory()->create(['name' => 'Bravo Hardware']);
        $this->worldA = $this->world($this->a, 'Ada', '50.00');
        $this->worldB = $this->world($this->b, 'Bola', '500.00');
    }

    public function test_erased_ownership_is_derived_again_from_what_owns_each_row(): void
    {
        try {
            $owners = $this->owners();
            $facts = $this->facts();

            $this->eraseOwnership();
            $this->up('sales', 'salesTenancy', 'children', 'childrenTenancy', 'purchasing', 'purchasingTenancy', 'expenses', 'expensesTenancy');

            $this->assertSame($owners, $this->owners(), 'Every row must be derived back to the business that owns it');
            $this->assertSame($facts, $this->facts(), 'No row, amount, number, identifier or stock level may change');

            // The walk-in sale has no customer: it can only have come from its seller.
            $this->assertNull(DB::table('sales')->where('id', $this->worldB['walkIn']->id)->value('customer_id'));
            $this->assertSame($this->b->id, DB::table('sales')->where('id', $this->worldB['walkIn']->id)->value('business_id'));

            foreach (['sales_business_id_sale_number_unique', 'sale_payments_business_id_payment_number_unique', 'sale_returns_business_id_return_number_unique',
                'sale_refunds_business_id_refund_number_unique', 'purchases_business_id_purchase_number_unique', 'expenses_business_id_expense_number_unique'] as $index) {
                $this->assertTrue($this->indexExists($index), "missing {$index}");
            }

            foreach (['sales_sale_number_unique', 'sale_payments_payment_number_unique', 'sale_returns_return_number_unique',
                'sale_refunds_refund_number_unique', 'purchases_purchase_number_unique', 'expenses_expense_number_unique'] as $index) {
                $this->assertFalse($this->indexExists($index), "{$index} must be replaced by its per-business form");
            }

            // Asked of the schema rather than of the data, so a table with no rows is covered too.
            foreach (self::DOCUMENTS as $table) {
                $this->assertSame('NO', DB::selectOne(
                    "SELECT IS_NULLABLE AS nullable FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'business_id'",
                    [$table]
                )->nullable, "{$table}.business_id must be NOT NULL");
            }

            $this->expectException(QueryException::class);
            DB::table('sales')->where('id', $this->worldA['sale']->id)->update(['business_id' => null]);
        } finally {
            $this->rebuildTestSchema();
        }
    }

    public function test_a_sale_whose_seller_and_customer_disagree_stops_the_backfill(): void
    {
        try {
            $this->eraseOwnership();
            // A's registered sale, now claiming B's administrator as its seller.
            DB::table('sales')->where('id', $this->worldA['sale']->id)->update(['sold_by' => $this->worldB['admin']->id]);

            $this->expectRefusal('sales', 'A sale was sold by a user of another business.');
        } finally {
            $this->rebuildTestSchema();
        }
    }

    public function test_children_that_disagree_with_their_parent_stop_the_backfill(): void
    {
        $cases = [
            'a line naming another business\'s product' => [fn (): array => ['sale_items', $this->worldA['item']->id, ['product_id' => $this->worldB['product']->id]],
                ['sales', 'salesTenancy'], 'children', 'sale_items.product_id references a products row of another business.'],
            'a return line naming another business\'s sale line' => [fn (): array => ['sale_return_items', $this->worldA['returnItem'], ['sale_item_id' => $this->worldB['paidItem']->id]],
                ['sales', 'salesTenancy'], 'children', 'sale_return_items.sale_item_id references a sale_items row of another business.'],
            'a purchase line naming another business\'s product' => [fn (): array => ['purchase_items', $this->worldA['purchaseItem'], ['product_id' => $this->worldB['product']->id]],
                ['sales', 'salesTenancy', 'children', 'childrenTenancy'], 'purchasing', 'A purchase line names a product of another business.'],
        ];
        $first = true;

        foreach ($cases as $label => [$corruption, $reapplied, $migration, $reason]) {
            try {
                if (! $first) {
                    // Each case starts from fresh history; the previous one ended with a clean rebuild.
                    $this->worldA = $this->world($this->a, 'Ada', '50.00');
                    $this->b = Business::factory()->create(['name' => 'Bravo Hardware']);
                    $this->worldB = $this->world($this->b, 'Bola', '500.00');
                }
                $first = false;

                [$table, $id, $change] = $corruption();
                $this->eraseOwnership();
                DB::table($table)->where('id', $id)->update($change);
                $this->up(...$reapplied);

                $this->expectRefusal($migration, $reason, $label);
            } finally {
                $this->rebuildTestSchema();
            }
        }
    }

    public function test_the_contract_refuses_a_stock_movement_recorded_against_another_businesss_document(): void
    {
        try {
            $this->down('tokensTenancy', 'whatsappTenancy', 'childrenTenancy');
            $movement = DB::table('inventory_movements')->where('reference_type', 'App\\Models\\Sale')->where('reference_id', $this->worldA['sale']->id)->value('id');
            DB::table('inventory_movements')->where('id', $movement)->update(['reference_id' => $this->worldB['sale']->id]);

            $this->expectRefusal('childrenTenancy', 'Stock movements reference sales rows of another business.');
        } finally {
            $this->rebuildTestSchema();
        }
    }

    public function test_rollback_refuses_when_two_businesses_share_a_document_number(): void
    {
        try {
            $number = $this->worldA['sale']->sale_number;
            DB::table('sales')->where('id', $this->worldB['sale']->id)->update(['sale_number' => $number]);

            $this->down('tokensTenancy', 'whatsappTenancy', 'expensesTenancy', 'purchasingTenancy', 'childrenTenancy');
            $this->expectRefusal('salesTenancy', 'global uniqueness cannot be restored', direction: 'down');

            $this->assertTrue($this->indexExists('sales_business_id_sale_number_unique'), 'A refused rollback must leave the schema as it was');
            $this->assertSame(2, DB::table('sales')->where('sale_number', $number)->count());
        } finally {
            $this->rebuildTestSchema();
        }
    }

    /* -------------------------------------------------------------------- helpers */

    /** Rolls every contract back and clears ownership, so the expand migrations must re-derive it. */
    private function eraseOwnership(): void
    {
        $this->down('tokensTenancy', 'whatsappTenancy', 'expensesTenancy', 'purchasingTenancy', 'childrenTenancy', 'salesTenancy');

        foreach (self::DOCUMENTS as $table) {
            DB::table($table)->update(['business_id' => null]);
        }
    }

    private function expectRefusal(string $migration, string $reason, string $label = '', string $direction = 'up'): void
    {
        try {
            $this->migrations[$migration]->{$direction}();
            $this->fail("The migration must refuse: {$label} {$reason}");
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($reason, $exception->getMessage(), $label);
        }
    }

    /** @return array<string, array<int, int>> table => id => business */
    private function owners(): array
    {
        return collect(self::DOCUMENTS)->mapWithKeys(fn (string $table): array => [
            $table => DB::table($table)->orderBy('id')->pluck('business_id', 'id')->map(fn ($id): int => (int) $id)->all(),
        ])->all();
    }

    /** @return array<string, mixed> */
    private function facts(): array
    {
        return [
            'counts' => collect(self::DOCUMENTS)->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all(),
            'sales' => DB::table('sales')->orderBy('id')->get(['id', 'public_id', 'sale_number', 'total_amount', 'amount_paid', 'balance_due', 'returned_amount', 'refunded_amount'])->map(fn ($row) => (array) $row)->all(),
            'payments' => DB::table('sale_payments')->orderBy('id')->get(['id', 'payment_number', 'amount'])->map(fn ($row) => (array) $row)->all(),
            'returns' => DB::table('sale_returns')->orderBy('id')->get(['id', 'return_number', 'merchandise_value'])->map(fn ($row) => (array) $row)->all(),
            'refunds' => DB::table('sale_refunds')->orderBy('id')->get(['id', 'refund_number', 'amount'])->map(fn ($row) => (array) $row)->all(),
            'purchases' => DB::table('purchases')->orderBy('id')->get(['id', 'purchase_number', 'total_amount'])->map(fn ($row) => (array) $row)->all(),
            'expenses' => DB::table('expenses')->orderBy('id')->get(['id', 'expense_number', 'amount'])->map(fn ($row) => (array) $row)->all(),
            'stock' => DB::table('products')->orderBy('id')->pluck('current_stock', 'id')->all(),
        ];
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

    private function indexExists(string $index): bool
    {
        return DB::selectOne(
            'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND INDEX_NAME = ? LIMIT 1',
            [$index]
        ) !== null;
    }
}
