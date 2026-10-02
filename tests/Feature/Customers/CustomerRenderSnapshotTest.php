<?php

namespace Tests\Feature\Customers;

use App\Actions\Sale\CreateSale;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Renders the Customer screens to files so they can be inspected in a real browser at real widths.
 *
 * Skipped unless CUST_SNAPSHOT_DIR is set, so it costs nothing in an ordinary run. It asserts
 * nothing about appearance — a test cannot see — it only produces the artefacts a human looks at.
 *
 * The test database is used because the development database has not had the additive migration
 * applied; that is deliberate and is the user's call to make.
 */
class CustomerRenderSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_render_the_customer_screens(): void
    {
        $directory = env('CUST_SNAPSHOT_DIR');

        if (! is_string($directory) || $directory === '') {
            $this->markTestSkipped('Set CUST_SNAPSHOT_DIR to render the screens.');
        }

        $this->assertSame('inventra_test', DB::connection()->getDatabaseName());

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin);

        // Empty state first, before any customer exists.
        $this->save($directory, 'customers-empty', $this->get(route('customers.index'))->getContent());

        $product = Product::factory()->create(['selling_price' => '21000.00', 'current_stock' => '400.000']);

        $people = [
            ['Emeka', 'Obi', '+2348012345678', null, 'emeka@example.com', 'Toyota Camry 2012', true, 4],
            ['Ngozi', 'Eze', '+2348025550192', '+2348099990000', 'ngozi@example.com', "Hilux '18", false, 6],
            ['Tunde', 'Bakare', '+2348034127788', null, null, "Corolla '15", true, 2],
            ['Bola', 'Ade', '+2348049013321', null, 'bola@example.com', "Accord '14", true, 3],
            ['Chioma', 'Okafor', '+2348052206644', null, 'chioma@example.com', "Sienna '16", false, 1],
        ];

        $first = null;

        foreach ($people as [$firstName, $lastName, $phone, $whatsApp, $email, $tag, $consent, $units]) {
            $customer = Customer::factory()->create([
                'first_name' => $firstName, 'last_name' => $lastName, 'phone' => $phone,
                'whatsapp_phone' => $whatsApp, 'email' => $email, 'tag' => $tag,
                'is_active' => true,
                'whatsapp_opt_in' => $consent,
                'whatsapp_opt_in_at' => $consent ? now() : null,
            ]);

            app(CreateSale::class)->execute($admin, [
                'is_walk_in' => false,
                'customer_id' => $customer->id,
                'sale_date' => CarbonImmutable::now(config('business.timezone'))->toDateString(),
                'products' => [['product_id' => $product->id, 'quantity' => (string) $units]],
                'payment_method' => 'cash',
                'amount_paid' => '0.00',
            ]);

            $first ??= $customer;
        }

        $this->save($directory, 'customers-list', $this->get(route('customers.index'))->getContent());
        $this->save($directory, 'customers-create', $this->get(route('customers.create'))->getContent());
        $this->save($directory, 'customers-profile', $this->get(route('customers.show', $first))->getContent());
        $this->save($directory, 'customers-edit', $this->get(route('customers.edit', $first))->getContent());

        // The duplicate-phone state, produced by a real refused submission.
        $this->from(route('customers.create'))->post(route('customers.store'), [
            'first_name' => 'Emeka', 'last_name' => 'Obi', 'phone' => '08012345678',
            'whatsapp_same_as_phone' => '1', 'email' => 'dup@example.com', 'tag' => 'Toyota Camry 2012',
        ]);
        $this->save($directory, 'customers-duplicate', $this->get(route('customers.create'))->getContent());

        $this->addToAssertionCount(1);
    }

    private function save(string $directory, string $name, string $html): void
    {
        // Asset URLs are rewritten so the saved file resolves the compiled stylesheet over HTTP.
        $html = str_replace(['http://localhost/build/', 'http://inventra.test/build/'], '/build/', $html);

        // Written only where it was asked for. Serving the artefact over HTTP, so the compiled
        // stylesheet resolves, is the caller's business — this leaves nothing in the repository.
        file_put_contents(rtrim($directory, '/').'/'.$name.'.html', $html);
    }
}
