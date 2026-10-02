<?php

namespace Tests\Feature\WhatsAppAutomation;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The Meta identity migration must survive MariaDB and must resume from a partially applied run.
 *
 * Production failed midway through this migration: MySQL and MariaDB commit DDL outside a
 * transaction, so the columns and indexes it had already created stayed while the CHECK swap did
 * not happen. `DROP CHECK` is MySQL-only spelling that MariaDB rejects outright, which is what
 * stopped it. These tests rebuild that exact half-applied shape and rerun the migration over it.
 */
class MetaIdentityMigrationRecoveryTest extends TestCase
{
    private const MIGRATION = 'database/migrations/2026_09_18_010000_add_meta_identity_to_whatsapp_connection.php';

    protected function setUp(): void
    {
        parent::setUp();

        // Never let a schema-mutating test touch anything but the isolated test database.
        static::assertSafeTestDatabase(DB::connection());
    }

    private function runMigration(): void
    {
        static::assertSafeTestDatabase(DB::connection());
        (require base_path(self::MIGRATION))->up();
    }

    private function checkExists(string $constraint): bool
    {
        return DB::selectOne(
            "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'whatsapp_connection'
               AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'",
            [$constraint]
        ) !== null;
    }

    private function indexExists(string $index): bool
    {
        return DB::selectOne(
            "SELECT INDEX_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'whatsapp_connection' AND INDEX_NAME = ?",
            [$index]
        ) !== null;
    }

    private function assertIntendedFinalSchema(): void
    {
        foreach ([
            'waba_id', 'phone_number_id', 'display_phone_number', 'verified_name',
            'business_id', 'access_token', 'token_expires_at', 'failure_reason',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('whatsapp_connection', $column), "missing column {$column}");
        }

        $this->assertTrue($this->indexExists('whatsapp_connection_waba_id_unique'));
        $this->assertTrue($this->indexExists('whatsapp_connection_phone_number_id_index'));

        // The singleton assumption is gone: no CHECK pinning the literal, no UNIQUE on the column.
        $this->assertFalse($this->checkExists('whatsapp_connection_singleton_valid'));
        $this->assertFalse($this->indexExists('whatsapp_connection_singleton_key_unique'));

        // And the completeness rule now demands the real Meta identity.
        $this->assertTrue($this->checkExists('whatsapp_connection_connected_is_complete'));
        $clause = DB::select(
            "SELECT CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'whatsapp_connection_connected_is_complete'"
        )[0]->CHECK_CLAUSE;
        $this->assertStringContainsString('waba_id', $clause);
        $this->assertStringContainsString('phone_number_id', $clause);
    }

    public function test_the_migration_reaches_the_intended_schema_when_already_fully_applied(): void
    {
        // Schema changes are not rolled back between tests, so this one asserts its own starting
        // point rather than inheriting whatever a previous test left behind.
        $this->runMigration();
        $this->assertIntendedFinalSchema();

        // Running it a second time over the completed schema must change nothing.
        $this->runMigration();
        $this->assertIntendedFinalSchema();
    }

    public function test_it_recovers_from_the_exact_production_partial_state(): void
    {
        // Rebuild what production actually had: columns and indexes applied, both old CHECKs still
        // present, singleton_key still UNIQUE, connected_is_complete still the pre-Meta clause.
        static::assertSafeTestDatabase(DB::connection());

        if ($this->checkExists('whatsapp_connection_connected_is_complete')) {
            DB::statement('ALTER TABLE whatsapp_connection DROP CONSTRAINT whatsapp_connection_connected_is_complete');
        }
        DB::statement("ALTER TABLE whatsapp_connection ADD CONSTRAINT whatsapp_connection_connected_is_complete CHECK (status <> 'connected' OR (phone_number IS NOT NULL AND verified_at IS NOT NULL))");

        if (! $this->checkExists('whatsapp_connection_singleton_valid')) {
            DB::statement("ALTER TABLE whatsapp_connection ADD CONSTRAINT whatsapp_connection_singleton_valid CHECK (singleton_key = 'whatsapp')");
        }

        if (! $this->indexExists('whatsapp_connection_singleton_key_unique')) {
            Schema::table('whatsapp_connection', function ($table): void {
                $table->unique('singleton_key', 'whatsapp_connection_singleton_key_unique');
            });
        }

        // Precondition: this is the production shape.
        $this->assertTrue($this->checkExists('whatsapp_connection_singleton_valid'));
        $this->assertTrue($this->indexExists('whatsapp_connection_singleton_key_unique'));
        $this->assertTrue(Schema::hasColumn('whatsapp_connection', 'waba_id'));

        $this->runMigration();

        $this->assertIntendedFinalSchema();
    }

    public function test_rerunning_after_recovery_is_still_safe(): void
    {
        $this->runMigration();
        $this->runMigration();

        $this->assertIntendedFinalSchema();
    }

    public function test_no_migration_uses_the_mysql_only_drop_check_spelling(): void
    {
        $offenders = [];

        foreach (glob(base_path('database/migrations/*.php')) as $file) {
            foreach (file($file) as $number => $line) {
                if (! str_contains($line, 'DROP CHECK')) {
                    continue;
                }
                if (preg_match('/^\s*(\/\/|\*)/', $line)) {
                    continue; // a comment explaining the rule is not a violation of it
                }
                $offenders[] = basename($file).':'.($number + 1);
            }
        }

        $this->assertSame([], $offenders, 'DROP CHECK is MySQL-only and fails on MariaDB; use DROP CONSTRAINT.');
    }
}
