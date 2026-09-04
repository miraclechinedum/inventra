<?php

namespace Tests\Feature\Expenses;

use App\Actions\Expense\CreateExpenseCategory;
use App\Actions\Expense\RecordExpense;
use App\Actions\Expense\UpdateExpenseCategory;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExpenseConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_independent_expenses_get_unique_numbers_and_category_updates_serialize(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('The pcntl extension is required for the independent-process probe.');
        }
        $actor = User::factory()->create(['role' => UserRole::Manager]);
        $category = app(CreateExpenseCategory::class)->execute($actor, ['name' => 'Original', 'description' => 'Start']);
        $tokens = [Str::random(64), Str::random(64)];
        foreach ($tokens as $token) {
            $request = new ExpenseRequest;
            $request->token_hash = hash('sha256', $token);
            $request->actor_id = $actor->id;
            $request->session_id = 'parallel';
            $request->expires_at = now()->addMinute();
            $request->save();
        }
        DB::commit();
        $barrier = tempnam(sys_get_temp_dir(), 'inventra-expense-');
        unlink($barrier);
        try {
            $pids = [];
            foreach ($tokens as $index => $token) {
                $pid = pcntl_fork();
                if ($pid === 0) {
                    while (! file_exists($barrier)) {
                        usleep(1_000);
                    }
                    try {
                        DB::purge();
                        $user = User::findOrFail($actor->id);
                        $fresh = ExpenseCategory::findOrFail($category->id);
                        app(RecordExpense::class)->execute($user, ['request_token' => $token, 'expense_category_id' => $fresh->id, 'amount' => '10.00', 'payment_method' => 'cash', 'description' => 'Parallel '.$index, 'incurred_at' => now()->toDateString()], 'parallel');
                        app(UpdateExpenseCategory::class)->execute($user, $fresh, ['name' => 'Category '.$index, 'description' => 'Edit '.$index]);
                        exit(0);
                    } catch (\Throwable) {
                        exit(1);
                    }
                }
                $this->assertGreaterThan(0, $pid);
                $pids[] = $pid;
            }
            touch($barrier);
            foreach ($pids as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertTrue(pcntl_wifexited($status));
                $this->assertSame(0, pcntl_wexitstatus($status));
            }
            DB::purge();
            $expenses = Expense::orderBy('id')->get();
            $this->assertCount(2, $expenses);
            $this->assertCount(2, $expenses->pluck('expense_number')->unique());
            $this->assertSame(2, AuditLog::where('action', 'expense_recorded')->count());
            $events = AuditLog::where('action', 'expense_category_updated')->where('auditable_id', $category->id)->orderBy('id')->get();
            $this->assertCount(2, $events);
            $this->assertSame($events[0]->new_values['name'], $events[1]->old_values['name']);
            $this->assertSame($events[1]->new_values['name'], ExpenseCategory::findOrFail($category->id)->name);
        } finally {
            if (file_exists($barrier)) {
                unlink($barrier);
            }
            DB::purge();
            Artisan::call('migrate:fresh', ['--force' => true]);
            DB::connection()->beginTransaction();
        }
    }
}
