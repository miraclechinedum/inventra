<?php
namespace Tests\Feature\BusinessSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB; use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
class MigrationRollbackCheckTest extends TestCase {
  use RefreshDatabase;
  protected function setUp(): void {
    parent::setUp();
    // These tests run migration down() and up() directly, so the live connection is re-proven first.
    static::assertSafeTestDatabase(DB::connection());
  }
  public function test_the_profile_migration_is_additive_and_rollback_safe(): void {
    $this->assertSame('inventra_test', DB::connection()->getDatabaseName(), 'test DB only');
    $cols = ['logo_path','business_type','currency','tax_number'];
    foreach ($cols as $c) $this->assertTrue(Schema::hasColumn('business_settings',$c), "$c present after up()");
    // A pre-existing row keeps its identity through down() then up().
    DB::table('business_settings')->update(['business_name'=>'Pre-existing Ltd','tax_number'=>'TAX-1']);
    $before = DB::table('business_settings')->firstOrFail();
    $m = require base_path('database/migrations/2026_09_19_010000_add_profile_fields_to_business_settings.php');
    $m->down();
    foreach ($cols as $c) $this->assertFalse(Schema::hasColumn('business_settings',$c), "$c dropped by down()");
    // The row itself survived the rollback: down() drops columns, never records.
    $this->assertSame(1, DB::table('business_settings')->count());
    $this->assertSame('Pre-existing Ltd', DB::table('business_settings')->value('business_name'));
    $m->up();
    foreach ($cols as $c) $this->assertTrue(Schema::hasColumn('business_settings',$c), "$c restored by up()");
    $after = DB::table('business_settings')->firstOrFail();
    $this->assertSame($before->id, $after->id);
    $this->assertSame('Pre-existing Ltd', $after->business_name);
    // Re-applying defaults the currency rather than leaving it null.
    $this->assertSame('NGN', $after->currency);
  }
  /**
   * The manager-alert migration is additive and leaves the existing record intact.
   *
   * A separate file from the profile-fields migration on purpose: that one has already run on
   * development, so extending it would have changed the meaning of a migration other databases
   * already record as applied.
   */
  public function test_the_manager_alert_migration_is_additive_and_rollback_safe(): void {
    $this->assertSame('inventra_test', DB::connection()->getDatabaseName(), 'test DB only');
    $this->assertTrue(Schema::hasColumn('business_settings','manager_alert_number'));

    DB::table('business_settings')->update([
      'business_name' => 'Pre-existing Ltd', 'business_email' => 'keep@example.com',
      'city' => 'Lagos', 'state' => 'Lagos', 'manager_alert_number' => '+2348011112222',
    ]);
    $before = DB::table('business_settings')->firstOrFail();

    $m = require base_path('database/migrations/2026_09_21_010000_add_manager_alert_number_to_business_settings.php');
    $m->down();
    $this->assertFalse(Schema::hasColumn('business_settings','manager_alert_number'), 'down() drops the column');

    // down() drops a column, never a record — and never the neighbouring data.
    $this->assertSame(1, DB::table('business_settings')->count());
    $rolled = DB::table('business_settings')->firstOrFail();
    $this->assertSame('Pre-existing Ltd', $rolled->business_name);
    $this->assertSame('keep@example.com', $rolled->business_email);
    $this->assertSame('Lagos', $rolled->city);
    $this->assertSame('Lagos', $rolled->state);

    $m->up();
    $this->assertTrue(Schema::hasColumn('business_settings','manager_alert_number'));
    $after = DB::table('business_settings')->firstOrFail();
    $this->assertSame($before->id, $after->id);
    $this->assertSame('Pre-existing Ltd', $after->business_name);
    // Re-adding the column starts it empty rather than inventing a destination.
    $this->assertNull($after->manager_alert_number);
    // And the hidden columns are still exactly as they were.
    $this->assertSame($before->business_email, $after->business_email);
    $this->assertSame($before->city, $after->city);
    $this->assertSame($before->state, $after->state);
  }

  /** A business-addressed message (no customer, no staff member) is accepted by the schema. */
  public function test_the_message_recipient_constraint_allows_a_business_addressed_row(): void {
    $this->assertSame('inventra_test', DB::connection()->getDatabaseName(), 'test DB only');

    $id = DB::table('whatsapp_messages')->insertGetId([
      'business_id' => DB::table('businesses')->value('id'),
      'type' => 'low_stock', 'customer_id' => null, 'user_id' => null,
      'recipient_name' => 'Akin Auto Parts', 'destination_phone' => '+2348011112222',
      'body' => 'Stock is low.', 'idempotency_key' => 'constraint-probe:1',
      'origin' => 'automatic', 'status' => 'queued', 'queued_at' => now(), 'attempt' => 1,
      'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->assertNotNull(DB::table('whatsapp_messages')->find($id));

    // The part of the old rule that still matters is kept: never both parties at once.
    $this->expectException(\Illuminate\Database\QueryException::class);
    DB::table('whatsapp_messages')->insert([
      'business_id' => DB::table('businesses')->value('id'),
      'type' => 'low_stock',
      'customer_id' => DB::table('customers')->insertGetId([
        'business_id' => DB::table('businesses')->value('id'), 'customer_code' => 'CUS-PROBE', 'first_name' => 'A', 'last_name' => 'B',
        'phone' => '+2348099998888', 'is_active' => true,
        'created_at' => now(), 'updated_at' => now(),
      ]),
      'user_id' => \App\Models\User::factory()->create()->id,
      'recipient_name' => 'Both', 'destination_phone' => '+2348011112222',
      'body' => 'x', 'idempotency_key' => 'constraint-probe:2',
      'origin' => 'automatic', 'status' => 'queued', 'queued_at' => now(), 'attempt' => 1,
      'created_at' => now(), 'updated_at' => now(),
    ]);
  }

  public function test_the_currency_check_constraint_refuses_a_non_iso_value(): void {
    foreach (['ngn','NG','NGNN','12X'] as $bad) {
      try { DB::table('business_settings')->update(['currency'=>$bad]); $this->fail("DB must refuse currency={$bad}"); }
      catch (\Throwable) { /* expected */ }
    }
    $this->assertSame('NGN', DB::table('business_settings')->value('currency'));
  }
}
