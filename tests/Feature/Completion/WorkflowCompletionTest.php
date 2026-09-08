<?php

namespace Tests\Feature\Completion;

use App\Actions\Sale\CreateSale;
use App\Contracts\WhatsAppClient;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Fakes\FakeWhatsAppClient;
use Tests\TestCase;

class WorkflowCompletionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->startSession();
        $this->withCookie(config('session.cookie'), $this->app['session']->driver()->getId());
    }

    public function test_management_can_complete_product_lifecycle_without_changing_movements(): void
    {
        foreach ([UserRole::Admin, UserRole::Manager] as $role) {
            $actor = User::factory()->create(['role' => $role]);
            $product = Product::factory()->create();
            $this->actingAs($actor)->post(route('inventory.products.adjust', $product), [
                'type' => 'restock', 'operation' => 'increase', 'quantity' => '1', 'reason' => 'Lifecycle history',
            ])->assertSessionHasNoErrors();
            $before = $product->movements()->get()->toArray();
            $this->assertNotEmpty($before);
            $this->actingAs($actor)->post(route('inventory.products.deactivate', $product))->assertRedirect();
            $this->assertFalse($product->fresh()->is_active);
            $this->get(route('inventory.products.show', $product))->assertOk()->assertSee(route('inventory.products.activate', $product), false);
            $this->post(route('inventory.products.activate', $product))->assertRedirect();
            $this->assertTrue($product->fresh()->is_active);
            $this->assertSame($before, $product->movements()->get()->toArray());
        }
    }

    public function test_rep_has_active_only_list_and_direct_access_without_cost_or_reactivation(): void
    {
        $rep = User::factory()->create(['role' => UserRole::SalesRep]);
        $active = Product::factory()->create(['name' => 'Visible catalogue product', 'cost_price' => '98765.43']);
        $inactive = Product::factory()->create(['is_active' => false, 'name' => 'Hidden inactive product']);
        $this->actingAs($rep)->get(route('inventory.index'))->assertOk()->assertSee($active->name)->assertDontSee($inactive->name)->assertDontSee('98,765.43');
        $this->get(route('inventory.products.show', $active))->assertOk()->assertDontSee('Cost price')->assertDontSee('98,765.43');
        $this->get(route('inventory.products.show', $inactive))->assertForbidden();
        $this->post(route('inventory.products.activate', $inactive))->assertForbidden();
        $this->assertFalse($inactive->fresh()->is_active);
    }

    public function test_rejected_stock_change_explains_itself_and_success_still_works(): void
    {
        $actor = User::factory()->create(['role' => UserRole::Manager]);
        $product = Product::factory()->create(['current_stock' => '2.000']);
        $url = route('inventory.products.show', $product);
        $before = $product->movements()->count();
        $this->actingAs($actor)->from($url)->post(route('inventory.products.adjust', $product), [
            'type' => 'loss', 'operation' => 'decrease', 'quantity' => '3', 'reason' => 'Damaged',
        ])->assertRedirect($url)->assertSessionHasErrors('quantity');
        $this->get($url)->assertOk()->assertSee('role="alert"', false)->assertSee('The adjustment cannot make stock negative.');
        $this->assertSame('2.000', $product->fresh()->current_stock);
        $this->assertSame($before, $product->movements()->count());
        $this->post(route('inventory.products.adjust', $product), [
            'type' => 'restock', 'operation' => 'increase', 'quantity' => '1', 'reason' => 'Received',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('3.000', $product->fresh()->current_stock);
    }

    public function test_search_returns_minimal_authorized_options_and_validation_restores_selected_products(): void
    {
        $actor = User::factory()->create(['role' => UserRole::SalesRep]);
        $customer = Customer::factory()->create();
        $a = Product::factory()->create(['name' => 'Alpha', 'selling_price' => '10.00', 'current_stock' => '10.000']);
        $b = Product::factory()->create(['name' => 'Zulu Beta', 'selling_price' => '20.00', 'current_stock' => '10.000']);
        $this->actingAs($actor);
        $result = $this->withCredentials()->getJson(route('sales.create', ['product_search' => 'Zulu']));
        $result->assertOk()->assertJsonCount(1, 'products')->assertJsonPath('products.0.id', $b->id);
        $this->assertSame(['id', 'label'], array_keys($result->json('products.0')));
        $draft = ['customer_id' => $customer->id, 'products' => [['product_id' => $a->id, 'quantity' => '2'], ['product_id' => $b->id, 'quantity' => '1']], 'payment_method' => 'transfer', 'amount_paid' => '50.00', 'notes' => 'Keep this draft'];
        $url = route('sales.create', ['product_search' => 'Zulu']);
        $this->from($url)->post(route('sales.store'), $draft)->assertRedirect($url)->assertSessionHasErrors('amount_paid');
        $this->get($url)->assertOk()->assertViewHas('errors', fn ($errors) => $errors->has('amount_paid'))->assertSee('Amount paid cannot exceed the sale total.')->assertSee('Keep this draft')->assertSee('Alpha')->assertSee('Zulu Beta');
        $this->assertSame(0, Sale::count());
        $this->assertSame('10.000', $a->fresh()->current_stock);
        $draft['amount_paid'] = '40.00';
        $this->post(route('sales.store'), $draft)->assertSessionHasNoErrors()->assertRedirect();
        $sale = Sale::sole();
        $this->assertSame($customer->id, $sale->customer_id);
        $this->assertSame('transfer', $sale->payment_method->value);
        $this->assertSame('40.00', $sale->total_amount);
        $this->assertCount(2, $sale->items);
        $this->assertSame('8.000', $a->fresh()->current_stock);
        $this->assertSame('9.000', $b->fresh()->current_stock);
    }

    public function test_search_never_authorizes_stale_prices_stock_or_inactive_products(): void
    {
        $actor = User::factory()->create(['role' => UserRole::SalesRep]);
        $customer = Customer::factory()->create();
        $product = Product::factory()->create(['name' => 'Search product', 'selling_price' => '10.00', 'current_stock' => '2.000']);
        Product::factory()->create(['name' => 'Search inactive', 'is_active' => false]);
        $this->actingAs($actor)->withCredentials()->getJson(route('sales.create', ['product_search' => 'Search']))
            ->assertOk()->assertJsonCount(1, 'products')->assertJsonPath('products.0.id', $product->id);
        $payload = ['customer_id' => $customer->id, 'products' => [['product_id' => $product->id, 'quantity' => '1']], 'payment_method' => 'cash', 'amount_paid' => '0'];
        $forged = $payload;
        $forged['products'][0]['unit_price'] = '0.01';
        $this->post(route('sales.store'), $forged)->assertSessionHasErrors('products.0.unit_price');
        $product->selling_price = '15.00';
        $product->save();
        $this->post(route('sales.store'), $payload)->assertSessionHasNoErrors();
        $sale = Sale::sole();
        $this->assertSame('15.00', $sale->total_amount);
        $this->assertSame('15.00', $sale->items()->sole()->unit_price);
        $payload['products'][0]['quantity'] = '2';
        $this->post(route('sales.store'), $payload)->assertSessionHasErrors('products');
        $this->assertSame('1.000', $product->fresh()->current_stock);
        $product->is_active = false;
        $product->save();
        $payload['products'][0]['quantity'] = '1';
        $this->post(route('sales.store'), $payload)->assertSessionHasErrors('products');
        $this->assertSame(1, Sale::count());
        $this->assertSame('1.000', $product->fresh()->current_stock);
    }

    public function test_payment_rejection_receipts_and_void_rejection_preserve_private_notes(): void
    {
        $actor = User::factory()->create(['role' => UserRole::Admin]);
        $customer = Customer::factory()->create();
        $product = Product::factory()->create(['selling_price' => '100.00', 'current_stock' => '5.000']);
        $sale = app(CreateSale::class)->execute($actor, ['customer_id' => $customer->id, 'products' => [['product_id' => $product->id, 'quantity' => '1']], 'payment_method' => 'cash', 'amount_paid' => '0', 'notes' => 'PRIVATE SALE NOTE']);
        $url = route('sales.show', $sale);
        $this->actingAs($actor);
        $token = $this->get($url)->viewData('paymentToken');
        $this->from($url)->post(route('sales.payments.store', $sale), ['request_token' => $token, 'amount' => '101', 'payment_method' => 'cash'])->assertSessionHasErrors('amount');
        $this->get($url)->assertSee('Payment cannot exceed the outstanding balance.')->assertSee('role="alert"', false);
        $this->assertSame(0, $sale->payments()->count());
        $note = '<script>alert("PRIVATE PAYMENT NOTE")</script>';
        $token = $this->get($url)->viewData('paymentToken');
        $this->post(route('sales.payments.store', $sale), ['request_token' => $token, 'amount' => '10', 'payment_method' => 'cash', 'note' => $note])->assertSessionHasNoErrors();
        $payment = $sale->payments()->sole();
        $this->get(route('sales.payments.show', [$sale, $payment]))->assertOk()->assertSee($note)->assertDontSee($note, false);
        foreach ([route('sales.receipt', $sale), route('sales.payments.receipt', [$sale, $payment])] as $receipt) {
            $this->get($receipt)->assertOk()->assertSee('data-print-trigger', false)->assertSee('inventra-receipt')->assertDontSee('PRIVATE PAYMENT NOTE')->assertDontSee('PRIVATE SALE NOTE');
        }
        $this->from($url)->post(route('sales.void', $sale), ['reason' => 'Cannot void settled sale'])->assertSessionHasErrors();
        $this->get($url)->assertSee('role="alert"', false);
        $this->assertSame('completed', $sale->fresh()->status->value);
        $this->assertSame('4.000', $product->fresh()->current_stock);
        $this->assertSame(1, $sale->payments()->count());
    }

    public function test_whatsapp_validation_is_visible_without_sending(): void
    {
        $client = new FakeWhatsAppClient;
        $client->configured = false;
        $this->app->instance(WhatsAppClient::class, $client);
        $actor = User::factory()->create(['role' => UserRole::Admin]);
        $sale = Sale::factory()->create(['sold_by' => $actor->id]);
        $url = route('sales.show', $sale);
        $this->actingAs($actor);
        $token = $this->get($url)->viewData('whatsappSendToken');
        $this->from($url)->post(route('sales.whatsapp.send', $sale), ['request_token' => $token])->assertSessionHasErrors('whatsapp');
        $this->get($url)->assertSee('WhatsApp receipt delivery is not configured.')->assertSee('role="alert"', false);
        $this->assertCount(0, $client->requests);
        $this->assertSame(0, $sale->whatsappDeliveries()->count());
    }
}
