<?php

namespace Tests\Feature\Expenses;

use App\Enums\UserRole;
use App\Models\ExpenseCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SharedScalarFormSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_array_shaped_scalar_fields_redirect_and_rerender_every_shared_form_safely(): void
    {
        $actor = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($actor)->startSession();

        $expenseForm = $this->get(route('expenses.create'))->assertOk();
        preg_match('/name="request_token" value="([^"]+)"/', $expenseForm->getContent(), $matches);
        $this->from(route('expenses.create'))->post(route('expenses.store'), [
            'request_token' => $matches[1], 'expense_category_id' => ['x'], 'amount' => ['10'],
            'incurred_at' => ['x'], 'payee' => ['x'], 'description' => ['x'],
        ])->assertRedirect(route('expenses.create'))->assertSessionHasErrors(['expense_category_id', 'amount', 'incurred_at', 'payee', 'description']);
        $this->get(route('expenses.create'))->assertOk();

        $this->from(route('expense-categories.create'))->post(route('expense-categories.store'), ['name' => ['x'], 'description' => ['x']])
            ->assertRedirect(route('expense-categories.create'))->assertSessionHasErrors(['name', 'description']);
        $this->get(route('expense-categories.create'))->assertOk();

        $category = $this->createCategory($actor);
        $this->from(route('expense-categories.edit', $category))->put(route('expense-categories.update', $category), ['name' => ['x'], 'description' => ['x']])
            ->assertRedirect(route('expense-categories.edit', $category))->assertSessionHasErrors(['name', 'description']);
        $this->get(route('expense-categories.edit', $category))->assertOk();

        $this->from(route('suppliers.create'))->post(route('suppliers.store'), ['name' => ['x']])->assertRedirect(route('suppliers.create'))->assertSessionHasErrors('name');
        $this->get(route('suppliers.create'))->assertOk();

        $this->from(route('customers.create'))->post(route('customers.store'), ['first_name' => ['x'], 'phone' => ['x']])->assertRedirect(route('customers.create'))->assertSessionHasErrors(['first_name', 'phone']);
        $this->get(route('customers.create'))->assertOk();

        $this->from(route('inventory.products.create'))->post(route('inventory.products.store'), ['name' => ['x']])->assertRedirect(route('inventory.products.create'))->assertSessionHasErrors('name');
        $this->get(route('inventory.products.create'))->assertOk();

        $this->assertDatabaseCount('expenses', 0);
        $this->assertDatabaseCount('suppliers', 0);
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('products', 0);
    }

    private function createCategory(User $actor): ExpenseCategory
    {
        $category = new ExpenseCategory;
        $category->category_code = 'EXPCAT-999999';
        $category->name = 'Existing';
        $category->is_active = true;
        $category->created_by = $actor->id;
        $category->save();

        return $category;
    }
}
