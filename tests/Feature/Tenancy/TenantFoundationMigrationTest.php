<?php

namespace Tests\Feature\Tenancy;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Concerns\UnwindsTenancyMigrations;
use Tests\TestCase;

/**
 * Demonstrates, on the test database only, how an existing single-business installation converts:
 * the tenant migrations are rolled back to rebuild the pre-tenancy shape, real-looking installation
 * data is placed in it, and the migrations are run forward again over that data.
 */
class TenantFoundationMigrationTest extends TestCase
{
    use DatabaseMigrations, UnwindsTenancyMigrations;

    private object $businesses;

    private object $ownership;

    private object $contract;

    protected function setUp(): void
    {
        parent::setUp();

        // These tests run migration down() and up() directly, so the live connection is re-proven first.
        static::assertSafeTestDatabase(DB::connection());

        $this->businesses = require database_path('migrations/2026_09_29_010000_create_businesses_table.php');
        $this->ownership = require database_path('migrations/2026_09_29_020000_add_business_ownership_to_users_and_business_settings.php');
        $this->contract = require database_path('migrations/2026_09_29_030000_enforce_one_settings_row_per_business.php');
    }

    public function test_an_existing_installation_becomes_its_first_business_without_losing_anyone(): void
    {
        $this->rollBackToPreTenancy();
        DB::table('business_settings')->update(['business_name' => 'Ada Motors Ltd']);
        $userIds = [$this->legacyUser('owner@example.com', UserRole::Admin), $this->legacyUser('clerk@example.com', UserRole::SalesRep)];
        $usersBefore = DB::table('users')->orderBy('id')->get(['id', 'email', 'role', 'status', 'password'])->toArray();

        $this->businesses->up();
        $this->ownership->up();
        $this->contract->up();
        $this->domainUp();

        $business = DB::table('businesses')->sole();
        $this->assertSame('Ada Motors Ltd', $business->name);
        $this->assertSame('active', $business->status);

        $this->assertEquals($usersBefore, DB::table('users')->orderBy('id')->get(['id', 'email', 'role', 'status', 'password'])->toArray());
        $this->assertSame(array_fill(0, 2, $business->id), DB::table('users')->whereIn('id', $userIds)->orderBy('id')->pluck('business_id')->all());

        $settings = DB::table('business_settings')->sole();
        $this->assertSame($business->id, $settings->business_id);
        $this->assertSame('Ada Motors Ltd', $settings->business_name);
        $this->assertFalse(Schema::hasColumn('business_settings', 'singleton_key'));
    }

    public function test_ownership_backfill_refuses_to_guess_between_businesses(): void
    {
        $this->domainDown();
        $this->contract->down();
        $this->ownership->down();
        $userId = $this->legacyUser('someone@example.com', UserRole::Manager);
        $original = DB::table('businesses')->value('id');
        $second = DB::table('businesses')->insertGetId(['name' => 'Unrelated', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        try {
            $this->ownership->up();
            $this->fail('The backfill must refuse when it cannot tell which business existing rows belong to.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('exactly one Business', $exception->getMessage());
        }

        $this->assertNull(DB::table('users')->where('id', $userId)->value('business_id'), 'No user may be assigned by a guess');

        // Leave the schema complete so the trait's closing rollback runs over a coherent state.
        DB::table('businesses')->where('id', $second)->delete();
        DB::table('business_settings')->update(['business_id' => $original]);
        DB::table('users')->whereNull('business_id')->update(['business_id' => $original]);
        $this->contract->up();
        $this->domainUp();
    }

    public function test_the_first_business_is_never_invented_without_a_settings_record(): void
    {
        $this->rollBackToPreTenancy();
        DB::table('business_settings')->delete();

        try {
            $this->businesses->up();
            $this->fail('A missing settings record must stop the conversion rather than invent a business.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('exactly one business_settings row', $exception->getMessage());
        }

        $this->assertSame(0, DB::table('businesses')->count());

        // Restore an installation the closing rollback can walk back through.
        DB::table('businesses')->insert(['name' => 'Restored', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('business_settings')->insert(['singleton_key' => 'business', 'business_name' => 'Restored', 'created_at' => now(), 'updated_at' => now()]);
        $this->ownership->up();
        $this->contract->up();
        $this->domainUp();
    }

    private function rollBackToPreTenancy(): void
    {
        $this->domainDown();
        $this->contract->down();
        $this->ownership->down();
        $this->businesses->down();

        $this->assertFalse(Schema::hasTable('businesses'));
        $this->assertFalse(Schema::hasColumn('users', 'business_id'));
        $this->assertSame('business', DB::table('business_settings')->value('singleton_key'));
    }

    private function domainDown(): void
    {
        $this->unwindTenancyFrom('2026_09_29_040000_enforce_business_ownership_of_users');
    }

    private function domainUp(): void
    {
        $this->restoreTenancyFrom('2026_09_29_040000_enforce_business_ownership_of_users');
    }

    private function legacyUser(string $email, UserRole $role): int
    {
        return DB::table('users')->insertGetId([
            'name' => ucfirst(strtok($email, '@')), 'email' => $email, 'password' => Hash::make('Password123'),
            'role' => $role->value, 'status' => UserStatus::Active->value,
            'force_password_change' => false, 'quick_pin_setup_completed' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
