<?php

namespace Tests\Feature\Tenancy;

use App\Actions\WhatsAppAutomation\RetryWhatsAppMessage;
use App\Actions\WhatsAppAutomation\WhatsAppAutomationTriggers;
use App\Contracts\WhatsAppConnectionProvider;
use App\Enums\UserRole;
use App\Models\Business;
use App\Models\BusinessSetting;
use App\Models\User;
use App\Models\WhatsAppAutomation;
use App\Models\WhatsAppMessage;
use App\Settings\BusinessSettings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\BuildsTransactionWorld;
use Tests\Concerns\BuildsWhatsAppWorld;
use Tests\Fakes\FakeWhatsAppProvider;
use Tests\TestCase;

/**
 * The WhatsApp and request-token ownership migrations over real history, on the test database only.
 *
 * The installation connects WhatsApp, runs its automations, retries a message, keeps a historical
 * receipt delivery and consumes every kind of request token through the real code. The migrations
 * are then rolled all the way back to the legacy single-business schema — where `business_id` on the
 * connection is again Meta's identifier — and re-applied. Every row must come back owned by the
 * Business that owns what it names, with every other byte unchanged, and every disagreement must
 * stop the migration rather than be resolved by picking a side.
 */
class WhatsAppOwnershipMigrationTest extends TestCase
{
    use BuildsTransactionWorld, BuildsWhatsAppWorld;

    private const OWNED = [
        'whatsapp_connection', 'whatsapp_automations', 'whatsapp_automation_recipients', 'whatsapp_messages',
        'whatsapp_low_stock_episodes', 'whatsapp_deliveries',
        'expense_requests', 'purchase_requests', 'sale_payment_requests', 'sale_refund_requests', 'sale_return_requests',
    ];

    private const META_BUSINESS = 'META-BUSINESS-7';

    /** @var array<string, object> */
    private array $migrations = [];

    private Business $a;

    /** @var array<string, mixed> */
    private array $worldA;

    protected function setUp(): void
    {
        parent::setUp();

        // These tests run migration down() and up() directly, so the live connection is re-proven first.
        static::assertSafeTestDatabase(DB::connection());
        $this->rebuildTestSchema();

        foreach ([
            'rename' => '2026_09_29_220000_rename_whatsapp_meta_business_identifier',
            'whatsapp' => '2026_09_29_221000_add_business_ownership_to_whatsapp',
            'whatsappTenancy' => '2026_09_29_222000_enforce_whatsapp_tenancy',
            'tokens' => '2026_09_29_223000_add_business_ownership_to_request_tokens',
            'tokensTenancy' => '2026_09_29_224000_enforce_request_token_tenancy',
        ] as $key => $name) {
            $this->migrations[$key] = require database_path("migrations/{$name}.php");
        }

        $this->app->instance(WhatsAppConnectionProvider::class, new FakeWhatsAppProvider);
        $this->a = Business::query()->orderBy('id')->firstOrFail();
        $this->worldA = $this->world($this->a, 'Ada', '50.00');
        $this->seedWhatsAppHistory();
    }

    public function test_the_legacy_schema_is_restored_and_ownership_derived_again_exactly(): void
    {
        try {
            $owners = $this->owners();
            $facts = $this->facts();

            $this->down('tokensTenancy', 'tokens', 'whatsappTenancy', 'whatsapp', 'rename');

            // Back on the legacy schema: `business_id` on the connection is Meta's identifier again.
            $this->assertSame(self::META_BUSINESS, DB::table('whatsapp_connection')->value('business_id'));
            $this->assertSame('varchar', $this->columnType('whatsapp_connection', 'business_id'));

            $this->up('rename', 'whatsapp', 'whatsappTenancy', 'tokens', 'tokensTenancy');

            $this->assertSame(self::META_BUSINESS, DB::table('whatsapp_connection')->value('meta_business_id'), 'the Meta value moves unchanged');
            $this->assertSame('bigint', $this->columnType('whatsapp_connection', 'business_id'));
            $this->assertSame($owners, $this->owners(), 'every row must be derived back to the business that owns it');
            $this->assertSame($facts, $this->facts(), 'no other byte may change — bodies, numbers, provider ids, ciphertext included');

            foreach (self::OWNED as $table) {
                $this->assertGreaterThan(0, DB::table($table)->count(), "{$table} must carry history for this test to mean anything");
                $this->assertSame('NO', $this->nullable($table), "{$table}.business_id must be NOT NULL");
            }

            foreach ([
                'whatsapp_connection_business_id_unique', 'whatsapp_connection_phone_number_id_unique', 'whatsapp_connection_waba_id_unique',
                'whatsapp_automations_business_id_key_unique', 'wa_recipients_user_tenant_fk', 'wa_messages_customer_tenant_fk',
                'wa_episodes_product_tenant_fk', 'wa_deliveries_sale_tenant_fk', 'sale_return_requests_sale_tenant_fk', 'expense_requests_actor_tenant_fk',
            ] as $index) {
                $this->assertTrue($this->indexExists($index), "missing {$index}");
            }
            $this->assertFalse($this->indexExists('whatsapp_automations_key_unique'), 'the global automation key must be replaced');

            foreach (self::OWNED as $table) {
                try {
                    DB::table($table)->limit(1)->update(['business_id' => null]);
                    $this->fail("{$table} must refuse a row without a business");
                } catch (QueryException) {
                    $this->addToAssertionCount(1);
                }
            }
        } finally {
            $this->rebuildTestSchema();
        }
    }

    public function test_legacy_configuration_is_never_guessed_once_a_second_business_exists(): void
    {
        try {
            $this->down('tokensTenancy', 'tokens', 'whatsappTenancy', 'whatsapp', 'rename');
            Business::factory()->create();

            $this->up('rename');
            $this->expectRefusal('whatsapp', 'whatsapp_connection rows predate tenancy and 2 businesses exist');
            $this->assertSame(0, DB::table('whatsapp_connection')->whereNotNull('business_id')->count(), 'nothing may be assigned to a default');
        } finally {
            $this->rebuildTestSchema();
        }
    }

    public function test_a_message_whose_sources_disagree_stops_the_backfill(): void
    {
        $this->withForeignRecords(function (array $b): void {
            // A's automation, sent to B's customer.
            $this->insertLegacyMessage(['customer_id' => $b['customer']->id]);

            $this->expectRefusal('whatsapp', 'A WhatsApp message names a whatsapp_automation_id of another business.');
        });
    }

    public function test_a_message_about_another_businesss_subject_stops_the_backfill(): void
    {
        $this->withForeignRecords(function (array $b): void {
            // A business-addressed low-stock alert from A's automation about B's product.
            $this->insertLegacyMessage(['subject_type' => 'App\\Models\\Product', 'subject_id' => $b['product']->id]);

            $this->expectRefusal('whatsapp', 'A WhatsApp message names a whatsapp_automation_id of another business.');
        });
    }

    public function test_a_recipient_of_another_business_stops_the_backfill(): void
    {
        $this->withForeignRecords(function (array $b): void {
            DB::table('whatsapp_automation_recipients')->insert([
                'whatsapp_automation_id' => DB::table('whatsapp_automations')->where('key', 'low_stock')->value('id'),
                'user_id' => $b['admin']->id, 'created_at' => now(), 'updated_at' => now(),
            ]);

            $this->expectRefusal('whatsapp', 'whatsapp_automation_recipients.user_id names a user of another business.');
        });
    }

    public function test_a_delivery_to_another_businesss_customer_stops_the_backfill(): void
    {
        $this->withForeignRecords(function (array $b): void {
            DB::table('whatsapp_deliveries')->update(['customer_id' => $b['customer']->id]);

            $this->expectRefusal('whatsapp', 'A WhatsApp delivery was addressed to a customer of another business.');
        });
    }

    public function test_a_request_token_for_another_businesss_sale_stops_the_backfill(): void
    {
        $this->withForeignRecords(function (array $b): void {
            DB::table('sale_payment_requests')->limit(1)->update(['sale_id' => $b['sale']->id]);

            $this->expectRefusal('tokens', 'sale_payment_requests rows were issued to an operator of a different business from their sale_id.');
        });
    }

    public function test_rollback_refuses_rather_than_merge_two_businesses_whatsapp_state(): void
    {
        try {
            $this->automationsFor(Business::factory()->create());
            $automations = DB::table('whatsapp_automations')->count();

            $this->expectRefusal('whatsappTenancy', 'More than one business has its own WhatsApp automations', direction: 'down');
            $this->assertTrue($this->indexExists('whatsapp_automations_business_id_key_unique'), 'a refused rollback changes nothing');
            $this->assertSame($automations, DB::table('whatsapp_automations')->count(), 'nothing may be merged or deleted');

            foreach (['whatsapp', 'tokens'] as $expand) {
                $this->expectRefusal($expand, 'while more than one business exists', $expand, 'down');
            }

            $this->expectRefusal('rename', 'roll back WhatsApp ownership first', direction: 'down');
        } finally {
            $this->rebuildTestSchema();
        }
    }

    /* -------------------------------------------------------------------- fixtures */

    private function seedWhatsAppHistory(): void
    {
        $admin = $this->worldA['admin'];
        $admin->forceFill(['phone' => '+2348031000001'])->save();
        $manager = User::factory()->forBusiness($this->a)->create(['role' => UserRole::Manager, 'phone' => '+2348032000001']);
        BusinessSetting::query()->where('business_id', $this->a->id)->update(['manager_alert_number' => '+2348030000001']);
        app(BusinessSettings::class)->forget();

        $connection = $this->connectWhatsApp($this->a, '100000000000001', 'PHONE_A', 'TOKEN_A');
        $connection->forceFill(['meta_business_id' => self::META_BUSINESS, 'connected_by' => $admin->id])->save();
        $welcome = $this->approvedAutomation($this->a);
        $lowStock = $this->approvedAutomation($this->a, WhatsAppAutomation::LOW_STOCK);
        $lowStock->syncRecipients([$manager->id]);

        // A customer message, failed and then retried by an operator.
        $failed = $this->queuedMessage($connection, $welcome, 'history:welcome');
        DB::table('whatsapp_messages')->where('id', $failed->id)->update(['status' => 'failed', 'failed_at' => now()]);
        app(RetryWhatsAppMessage::class)->execute($admin, WhatsAppMessage::query()->findOrFail($failed->id));

        // A business-addressed low-stock alert and its episode, through the real trigger.
        DB::table('products')->where('id', $this->worldA['product']->id)->update(['current_stock' => '1.000', 'reorder_level' => '5.000']);
        app(WhatsAppAutomationTriggers::class)->stockChanged($this->worldA['product']->fresh());

        // A historical receipt delivery from the retired receipt feature.
        DB::table('whatsapp_deliveries')->insert([
            'business_id' => $this->a->id, 'sale_id' => $this->worldA['sale']->id, 'customer_id' => $this->worldA['customer']->id,
            'request_id' => (string) Str::uuid(), 'origin' => 'manual', 'destination_phone' => '+2348031234567',
            'consent_checked_at' => now(), 'requested_at' => now(), 'status' => 'sent', 'attempt' => 1,
            'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Opens the contracts so ownership can be erased and re-derived, gives a second Business records
     * to be wrongly referenced, and runs $work. Only derived ownership is erased: the legacy
     * connection and automations stay owned, since a second Business makes them unattributable by
     * design (proved separately).
     */
    private function withForeignRecords(\Closure $work): void
    {
        try {
            $b = $this->world(Business::factory()->create(['name' => 'Bravo Hardware']), 'Bola', '500.00');
            $this->down('tokensTenancy', 'whatsappTenancy');

            foreach (['whatsapp_automation_recipients', 'whatsapp_messages', 'whatsapp_low_stock_episodes', 'whatsapp_deliveries',
                'expense_requests', 'purchase_requests', 'sale_payment_requests', 'sale_refund_requests', 'sale_return_requests'] as $table) {
                DB::table($table)->update(['business_id' => null]);
            }

            $work($b);
        } finally {
            $this->rebuildTestSchema();
        }
    }

    /** @param array<string, mixed> $overrides */
    private function insertLegacyMessage(array $overrides): void
    {
        DB::table('whatsapp_messages')->insert($overrides + [
            'whatsapp_automation_id' => DB::table('whatsapp_automations')->where('key', 'welcome')->value('id'),
            'type' => 'welcome', 'recipient_name' => 'Legacy', 'destination_phone' => '+2348039999999', 'body' => 'Hi',
            'idempotency_key' => 'legacy:'.Str::uuid(), 'origin' => 'automatic', 'status' => 'sent', 'queued_at' => now(),
            'attempt' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array<string, array<int, int|null>> */
    private function owners(): array
    {
        return collect(self::OWNED)->mapWithKeys(fn (string $table): array => [
            $table => DB::table($table)->orderBy('id')->pluck('business_id', 'id')->all(),
        ])->all();
    }

    /** @return array<string, list<array<string, mixed>>> every column except the tenant key being derived */
    private function facts(): array
    {
        return collect(self::OWNED)->mapWithKeys(fn (string $table): array => [
            $table => DB::table($table)->orderBy('id')->get()->map(function (object $row): array {
                $row = (array) $row;
                unset($row['business_id']);
                ksort($row);

                return $row;
            })->all(),
        ])->all();
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

    private function expectRefusal(string $migration, string $reason, string $label = '', string $direction = 'up'): void
    {
        try {
            $this->migrations[$migration]->{$direction}();
            $this->fail("The migration must refuse: {$label} {$reason}");
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($reason, $exception->getMessage(), $label);
        }
    }

    private function columnType(string $table, string $column): ?string
    {
        return DB::selectOne(
            'SELECT DATA_TYPE AS type FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        )?->type;
    }

    private function nullable(string $table): string
    {
        return DB::selectOne(
            "SELECT IS_NULLABLE AS nullable FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'business_id'",
            [$table]
        )->nullable;
    }

    private function indexExists(string $index): bool
    {
        return DB::selectOne(
            'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND INDEX_NAME = ? LIMIT 1',
            [$index]
        ) !== null;
    }
}
