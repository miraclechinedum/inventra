<?php

namespace Tests\Feature\Expenses;

use App\Actions\Expense\CreateExpenseCategory;
use App\Actions\Expense\RecordExpense;
use App\Enums\UserRole;
use App\Models\ExpenseRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class ExpenseMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_empty_tables_can_roll_back_and_reapply(): void
    {
        $migration = $this->migration();
        $hardening = $this->hardeningMigration();
        try {
            $hardening->down();
            $migration->down();
            $this->assertFalse(Schema::hasTable('expenses'));
            $this->assertFalse(Schema::hasTable('expense_categories'));
        } finally {
            $migration->up();
            $hardening->up();
        }
    }

    public function test_category_data_refuses_rollback_and_is_preserved(): void
    {
        $actor = User::factory()->create(['role' => UserRole::Admin]);
        $category = app(CreateExpenseCategory::class)->execute($actor, ['name' => 'Preserved']);
        try {
            $this->migration()->down();
            $this->fail('Rollback accepted Category history.');
        } catch (RuntimeException) {
            $this->assertDatabaseHas('expense_categories', ['id' => $category->id]);
            $this->assertTrue(Schema::hasTable('expenses'));
        } finally {
            $this->clean();
        }
    }

    public function test_expense_history_refuses_rollback_and_is_preserved(): void
    {
        $actor = User::factory()->create(['role' => UserRole::Manager]);
        $category = app(CreateExpenseCategory::class)->execute($actor, ['name' => 'Historical']);
        $token = Str::random(64);
        $request = new ExpenseRequest;
        $request->token_hash = hash('sha256', $token);
        $request->actor_id = $actor->id;
        $request->session_id = 'migration-session';
        $request->expires_at = now()->addMinute();
        $request->save();
        $expense = app(RecordExpense::class)->execute($actor, ['request_token' => $token, 'expense_category_id' => $category->id, 'amount' => '20.00', 'payment_method' => 'cash', 'description' => 'Historical', 'incurred_at' => now()->toDateString()], 'migration-session');
        try {
            $this->migration()->down();
            $this->fail('Rollback accepted Expense history.');
        } catch (RuntimeException) {
            $this->assertDatabaseHas('expenses', ['id' => $expense->id]);
            $this->assertDatabaseHas('expense_requests', ['expense_id' => $expense->id]);
            $this->assertDatabaseHas('expense_categories', ['id' => $category->id]);
        } finally {
            $this->clean();
        }
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_09_04_030000_create_expense_management_tables.php');
    }

    private function hardeningMigration(): object
    {
        return require database_path('migrations/2026_09_04_040000_harden_expense_deletion_integrity.php');
    }

    private function clean(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('expense_requests')->delete();
        DB::table('expenses')->delete();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        DB::table('audit_logs')->whereIn('action', ['expense_recorded', 'expense_category_created'])->delete();
        DB::table('expense_categories')->delete();
    }
}
