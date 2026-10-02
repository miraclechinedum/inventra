<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The additive customer columns.
 *
 * Three nullable fields were added for the Customer screens. The point of these checks is that the
 * addition is genuinely additive: a customer row that predates the change — every column null —
 * must still load, save and behave exactly as it did, with no backfill needed to make it valid.
 */
class CustomerSchemaMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // This suite writes. Asserted on the connection's own name rather than the environment,
        // because only the database can say which database it is.
        $this->assertSame(
            'inventra_test',
            DB::connection()->getDatabaseName(),
            'Refusing to run: these tests write, and this is not the test database.'
        );
    }

    public function test_the_three_columns_exist_and_are_nullable(): void
    {
        foreach (['photo_path', 'tag', 'whatsapp_phone'] as $column) {
            $this->assertTrue(
                Schema::hasColumn('customers', $column),
                "customers.{$column} is missing"
            );
        }

        // Nullable with no default: a row written before the migration needs nothing done to it.
        $customer = Customer::factory()->create();

        $this->assertNull($customer->photo_path);
        $this->assertNull($customer->tag);
        $this->assertNull($customer->whatsapp_phone);
    }

    /** Nothing that existed before was renamed, dropped or repurposed. */
    public function test_every_pre_existing_column_survives(): void
    {
        foreach ([
            'id', 'customer_code', 'first_name', 'last_name', 'phone', 'email', 'address', 'city',
            'notes', 'is_active', 'whatsapp_opt_in', 'whatsapp_opt_in_at', 'whatsapp_opt_out_at',
            'created_by', 'updated_by', 'created_at', 'updated_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('customers', $column), "customers.{$column} was lost");
        }
    }

    /** `notes` is untouched: a tag is not a note, and the migration does not conflate them. */
    public function test_notes_and_tag_are_independent(): void
    {
        $customer = Customer::factory()->create([
            'notes' => 'Prefers morning collection.',
            'tag' => 'Toyota Camry 2012',
        ]);

        $customer->refresh();

        $this->assertSame('Prefers morning collection.', $customer->notes);
        $this->assertSame('Toyota Camry 2012', $customer->tag);
    }

    /** A customer created before the migration still works with all three fields null. */
    public function test_a_legacy_shaped_customer_still_saves_and_loads(): void
    {
        $customer = Customer::factory()->create([
            'photo_path' => null,
            'tag' => null,
            'whatsapp_phone' => null,
        ]);

        $customer->notes = 'Still editable.';
        $customer->save();

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'notes' => 'Still editable.',
            'photo_path' => null,
            'tag' => null,
            'whatsapp_phone' => null,
        ]);
    }

    public function test_the_tag_column_holds_its_stated_length(): void
    {
        $tag = str_repeat('a', 120);
        $customer = Customer::factory()->create(['tag' => $tag]);

        $this->assertSame($tag, $customer->fresh()->tag);
    }
}
