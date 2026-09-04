<?php

namespace Tests\Feature\Expenses;

use App\Actions\Expense\CreateExpenseCategory;
use App\Actions\Expense\RecordExpense;
use App\Actions\Expense\SetExpenseCategoryActive;
use App\Actions\Expense\UpdateExpenseCategory;
use App\Enums\UserRole;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRequest;
use App\Models\Product;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Mockery;
use Tests\TestCase;

class ExpenseFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorization_matrix_and_navigation_confidentiality(): void
    {
        foreach ([UserRole::Admin, UserRole::Manager] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->get(route('expenses.index'))->assertOk()->assertSee('Expenses');
            $this->get(route('expense-categories.index'))->assertOk();
        }
        $salesRep = User::factory()->create(['role' => UserRole::SalesRep]);
        $this->actingAs($salesRep)->get(route('expenses.index'))->assertForbidden();
        $this->get(route('expenses.create'))->assertForbidden();
        $this->get(route('expense-categories.index'))->assertForbidden();
        auth()->logout();
        $this->get(route('expenses.index'))->assertRedirect(route('login'));
    }

    public function test_categories_have_race_safe_codes_lifecycle_noop_and_audit(): void
    {
        $actor = User::factory()->create(['role' => UserRole::Manager]);
        $category = app(CreateExpenseCategory::class)->execute($actor, ['name' => 'Fuel']);
        $duplicateName = app(CreateExpenseCategory::class)->execute($actor, ['name' => 'Fuel']);
        $this->assertSame('EXPCAT-'.str_pad((string) $category->id, 6, '0', STR_PAD_LEFT), $category->category_code);
        $this->assertNotSame($category->category_code, $duplicateName->category_code);
        $before = DB::table('audit_logs')->where('action', 'expense_category_updated')->count();
        app(UpdateExpenseCategory::class)->execute($actor, $category, ['name' => 'Fuel']);
        $this->assertSame($before, DB::table('audit_logs')->where('action', 'expense_category_updated')->count());
        app(SetExpenseCategoryActive::class)->execute($actor, $category, false);
        $this->assertFalse($category->fresh()->is_active);
        app(SetExpenseCategoryActive::class)->execute($actor, $category, true);
        $this->assertTrue($category->fresh()->is_active);
        $this->assertDatabaseHas('audit_logs', ['action' => 'expense_category_created', 'auditable_id' => $category->id]);
    }

    public function test_recording_is_exact_snapshotted_immutable_and_has_no_cross_domain_effect(): void
    {
        $actor = User::factory()->create(['role' => UserRole::Admin, 'name' => 'Jane Manager']);
        $category = app(CreateExpenseCategory::class)->execute($actor, ['name' => 'Fuel']);
        $product = Product::factory()->create(['current_stock' => '8.500']);
        $counts = collect(['purchases', 'purchase_items', 'inventory_movements', 'sale_payments', 'whatsapp_deliveries'])->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()]);
        $expense = $this->record($actor, $category, ['amount' => '35000.10', 'description' => 'Generator fuel']);
        $this->assertSame('EXP-'.str_pad((string) $expense->id, 6, '0', STR_PAD_LEFT), $expense->expense_number);
        $this->assertSame('35000.10', $expense->amount);
        $this->assertSame('Fuel', $expense->category_name_snapshot);
        $this->assertSame('Jane Manager', $expense->recorded_by_name_snapshot);
        $this->assertSame('8.500', $product->fresh()->current_stock);
        foreach ($counts as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), $table.' changed');
        }
        $this->expectException(LogicException::class);
        $expense->delete();
    }

    public function test_category_and_recorder_renames_do_not_change_history(): void
    {
        $actor = User::factory()->create(['role' => UserRole::Manager, 'name' => 'Jane Manager']);
        $category = app(CreateExpenseCategory::class)->execute($actor, ['name' => 'Fuel']);
        $expense = $this->record($actor, $category);
        app(UpdateExpenseCategory::class)->execute($actor, $category, ['name' => 'Generator Fuel']);
        $actor->name = 'Jane Smith';
        $actor->save();
        $expense->refresh();
        $this->assertSame('Fuel', $expense->category_name_snapshot);
        $this->assertSame('Jane Manager', $expense->recorded_by_name_snapshot);
        $this->actingAs($actor)->get(route('expenses.show', $expense))->assertSee('Fuel')->assertSee('Jane Manager')->assertDontSee('Generator Fuel');
    }

    public function test_inactive_category_race_rejects_without_consuming_token(): void
    {
        $actor = User::factory()->create(['role' => UserRole::Manager]);
        $category = app(CreateExpenseCategory::class)->execute($actor, ['name' => 'Utilities']);
        [$token, $data] = $this->tokenAndData($actor, $category);
        app(SetExpenseCategoryActive::class)->execute($actor, $category, false);
        try {
            app(RecordExpense::class)->execute($actor, $data, 'expense-session');
            $this->fail('Inactive category accepted.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
        $this->assertDatabaseCount('expenses', 0);
        $this->assertNull(ExpenseRequest::where('token_hash', hash('sha256', $token))->firstOrFail()->used_at);
    }

    public function test_idempotency_replay_changed_payload_and_cross_context_rejection(): void
    {
        $actor = User::factory()->create(['role' => UserRole::Admin]);
        $other = User::factory()->create(['role' => UserRole::Manager]);
        $category = app(CreateExpenseCategory::class)->execute($actor, ['name' => 'Fuel']);
        [, $data] = $this->tokenAndData($actor, $category);
        $first = app(RecordExpense::class)->execute($actor, $data, 'expense-session');
        $changed = array_merge($data, ['amount' => '999999.00', 'description' => 'Changed']);
        $this->assertTrue($first->is(app(RecordExpense::class)->execute($actor, $changed, 'expense-session')));
        $this->assertDatabaseCount('expenses', 1);
        [, $unused] = $this->tokenAndData($actor, $category);
        foreach ([[$other, $unused, 'expense-session'], [$actor, $unused, 'other-session']] as [$user, $payload, $session]) {
            try {
                app(RecordExpense::class)->execute($user, $payload, $session);
                $this->fail('Cross-context token accepted.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_http_same_session_creation_replay_and_cross_session(): void
    {
        $actor = User::factory()->create(['role' => UserRole::Manager]);
        $category = app(CreateExpenseCategory::class)->execute($actor, ['name' => 'Rent']);
        $this->actingAs($actor)->startSession();
        $response = $this->get(route('expenses.create'))->assertOk();
        preg_match('/name="request_token" value="([^"]+)"/', $response->getContent(), $matches);
        $issued = ExpenseRequest::where('token_hash', hash('sha256', $matches[1]))->firstOrFail();
        $payload = ['request_token' => $matches[1], 'expense_category_id' => $category->id, 'amount' => '10000.00', 'payment_method' => 'transfer', 'description' => 'Monthly rent', 'incurred_at' => now()->subDay()->toDateString()];
        $cookie = config('session.cookie');
        $this->withCookie($cookie, $issued->session_id)->post(route('expenses.store'), $payload)->assertRedirect();
        $expense = Expense::firstOrFail();
        $this->withCookie($cookie, $issued->session_id)->post(route('expenses.store'), array_merge($payload, ['amount' => '1.00']))->assertRedirect(route('expenses.show', $expense));
        $this->assertDatabaseCount('expenses', 1);
        $this->assertNotNull($issued->fresh()->used_at);
        [, $otherData] = $this->tokenAndData($actor, $category);
        $this->post(route('expenses.store'), $otherData)->assertSessionHasErrors('request_token');
    }

    public function test_validation_rejects_money_future_dates_shapes_and_privileged_fields(): void
    {
        $actor = User::factory()->create(['role' => UserRole::Admin]);
        $category = app(CreateExpenseCategory::class)->execute($actor, ['name' => 'Validation']);
        foreach (['0', '-1', '1.001', '1e2', '1,000', 'NaN', 'Infinity'] as $amount) {
            $this->actingAs($actor)->post(route('expenses.store'), ['amount' => $amount, 'incurred_at' => now()->addDay()->toDateString(), 'expense_number' => 'HACK'])->assertSessionHasErrors(['amount', 'incurred_at', 'expense_number']);
        }
        $this->post(route('expenses.store'), ['amount' => ['10'], 'incurred_at' => ['bad'], 'recorded_by' => 999])->assertSessionHasErrors(['amount', 'incurred_at', 'recorded_by']);
        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_random_malformed_and_expired_tokens_are_controlled(): void
    {
        $actor = User::factory()->create(['role' => UserRole::Admin]);
        $category = app(CreateExpenseCategory::class)->execute($actor, ['name' => 'Tokens']);
        [, $data] = $this->tokenAndData($actor, $category);
        ExpenseRequest::where('token_hash', hash('sha256', $data['request_token']))->update(['expires_at' => now()->subMinute()]);
        try {
            app(RecordExpense::class)->execute($actor, $data, 'expense-session');
            $this->fail('Expired token accepted.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
        $data['request_token'] = Str::random(64);
        try {
            app(RecordExpense::class)->execute($actor, $data, 'expense-session');
            $this->fail('Random token accepted.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
        $this->actingAs($actor)->post(route('expenses.store'), array_merge($data, ['request_token' => ['nested']]))->assertSessionHasErrors('request_token');
        $this->assertDatabaseCount('expenses', 0);
    }

    public function test_category_validation_is_visible_and_delete_or_expense_edit_routes_do_not_exist(): void
    {
        $actor = User::factory()->create(['role' => UserRole::Manager]);
        $this->actingAs($actor)->get(route('expense-categories.create'))->assertOk();
        $this->actingAs($actor)->followingRedirects()->post(route('expense-categories.store'), [
            'name' => '', 'description' => str_repeat('x', 501), 'category_code' => 'FORGED', 'is_active' => false,
        ])->assertSee('The name field is required.')->assertSee('The description field must not be greater than 500 characters.')->assertSee('The category code field is prohibited.')->assertSee('The is active field is prohibited.');
        $this->assertFalse(Route::has('expenses.update'));
        $this->assertFalse(Route::has('expenses.destroy'));
        $this->assertFalse(Route::has('expense-categories.destroy'));
    }

    public function test_filters_are_array_safe_literal_and_summaries_match(): void
    {
        $actor = User::factory()->create(['role' => UserRole::Admin]);
        $category = app(CreateExpenseCategory::class)->execute($actor, ['name' => 'Fuel%_\\']);
        $expense = $this->record($actor, $category, ['amount' => '12.34', 'description' => 'Literal%_\\']);
        $this->actingAs($actor)->get(route('expenses.index', ['search' => 'Literal%_\\']))->assertOk()->assertSee($expense->expense_number)->assertSee('12.34');
        $this->get(route('expenses.index', ['search' => ['x'], 'category' => ['x'], 'payment_method' => ['x'], 'recorded_by' => ['x'], 'from' => ['x'], 'to' => ['x'], 'page' => ['x']]))->assertOk();
        $this->get(route('expense-categories.index', ['search' => ['x'], 'status' => ['x'], 'page' => ['x']]))->assertOk();
    }

    public function test_xss_is_escaped_and_note_is_excluded_from_voucher(): void
    {
        $actor = User::factory()->create(['role' => UserRole::Manager]);
        $category = app(CreateExpenseCategory::class)->execute($actor, ['name' => '<script>Category</script>', 'description' => '<img src=x onerror=alert(1)>']);
        $expense = $this->record($actor, $category, ['payee' => '"><svg onload=alert(1)>', 'description' => '<script>alert(1)</script>', 'note' => 'SENTINEL-PRIVATE-NOTE']);
        $this->actingAs($actor)->get(route('expenses.show', $expense))->assertSee('&lt;script&gt;', false)->assertDontSee('<script>', false);
        $this->get(route('expenses.receipt', $expense))->assertDontSee('SENTINEL-PRIVATE-NOTE')->assertDontSee('onclick=', false);
        $this->get(route('expense-categories.show', $category))->assertSee('&lt;img src=x onerror=alert(1)&gt;', false)->assertDontSee('<img src=x onerror=alert(1)>', false);
    }

    public function test_sales_rep_accessible_pages_do_not_leak_expense_sentinels(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $category = app(CreateExpenseCategory::class)->execute($admin, ['name' => 'SENTINEL-EXPENSE-CATEGORY']);
        $this->record($admin, $category, ['amount' => '987654.32', 'payee' => 'SENTINEL-PAYEE', 'description' => 'SENTINEL-DESCRIPTION', 'note' => 'SENTINEL-PRIVATE-NOTE', 'reference_number' => 'SENTINEL-REFERENCE']);
        $rep = User::factory()->create(['role' => UserRole::SalesRep]);
        foreach ([route('dashboard'), route('inventory.index'), route('sales.index'), route('customers.index')] as $url) {
            $content = $this->actingAs($rep)->get($url)->assertOk()->getContent();
            foreach (['SENTINEL-EXPENSE-CATEGORY', '987654.32', 'SENTINEL-PAYEE', 'SENTINEL-DESCRIPTION', 'SENTINEL-PRIVATE-NOTE', 'SENTINEL-REFERENCE'] as $sentinel) {
                $this->assertStringNotContainsString($sentinel, $content);
            }
        }
    }

    public function test_database_constraints_and_model_guards_reject_invalid_history(): void
    {
        $actor = User::factory()->create(['role' => UserRole::Admin]);
        $category = app(CreateExpenseCategory::class)->execute($actor, ['name' => 'Constraints']);
        $expense = $this->record($actor, $category);
        $base = $expense->getAttributes();
        unset($base['id']);
        $request = ExpenseRequest::where('expense_id', $expense->id)->firstOrFail();
        foreach ([
            fn () => DB::table('expenses')->insert(array_merge($base, ['expense_number' => 'EXP-ZERO', 'amount' => 0])),
            fn () => DB::table('expenses')->insert(array_merge($base, ['expense_number' => 'EXP-NEGATIVE', 'amount' => -1])),
            fn () => DB::table('expenses')->insert(array_merge($base, ['expense_number' => $expense->expense_number])),
            fn () => DB::table('expenses')->insert(array_merge($base, ['expense_number' => 'EXP-BADCATEGORY', 'expense_category_id' => 999999])),
            fn () => DB::table('expenses')->insert(array_merge($base, ['expense_number' => 'EXP-BADRECORDER', 'recorded_by' => 999999])),
            fn () => DB::table('expenses')->insert(array_merge($base, ['expense_number' => 'EXP-BADMETHOD', 'payment_method' => 'credit'])),
            fn () => DB::table('expenses')->insert(array_merge($base, ['expense_number' => 'EXP-NODATE', 'incurred_at' => null])),
            fn () => DB::table('expense_categories')->insert(array_merge($category->getAttributes(), ['id' => null])),
            fn () => DB::table('expense_requests')->insert(array_merge($request->getAttributes(), ['id' => null, 'expense_id' => null])),
            fn () => DB::table('expense_requests')->insert(array_merge($request->getAttributes(), ['id' => null, 'token_hash' => hash('sha256', 'different')])),
        ] as $probe) {
            try {
                $probe();
                $this->fail('Database constraint accepted invalid row.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
        $expense->amount = '1.00';
        $this->expectException(LogicException::class);
        $expense->save();
    }

    public function test_audit_failure_rolls_back_expense_and_token(): void
    {
        $actor = User::factory()->create(['role' => UserRole::Admin]);
        $category = app(CreateExpenseCategory::class)->execute($actor, ['name' => 'Rollback']);
        [$token, $data] = $this->tokenAndData($actor, $category);
        $audit = Mockery::mock(AuditLogger::class);
        $audit->shouldReceive('record')->andThrow(new \RuntimeException('audit failed'));
        try {
            (new RecordExpense($audit))->execute($actor, $data, 'expense-session');
            $this->fail('Audit failure accepted.');
        } catch (\RuntimeException) {
            $this->assertTrue(true);
        }
        $this->assertDatabaseCount('expenses', 0);
        $this->assertNull(ExpenseRequest::where('token_hash', hash('sha256', $token))->firstOrFail()->used_at);
    }

    public function test_expense_audit_excludes_private_descriptive_fields_everywhere(): void
    {
        $actor = User::factory()->create(['role' => UserRole::Admin]);
        $category = app(CreateExpenseCategory::class)->execute($actor, ['name' => 'Privacy']);
        $expense = $this->record($actor, $category, [
            'payee' => 'SENTINEL-AUDIT-PAYEE',
            'reference_number' => 'SENTINEL-AUDIT-REFERENCE',
            'description' => 'SENTINEL-AUDIT-DESCRIPTION',
            'note' => 'SENTINEL-AUDIT-NOTE',
        ]);
        $audit = DB::table('audit_logs')->where('action', 'expense_recorded')->where('auditable_id', $expense->id)->firstOrFail();
        $serialized = json_encode($audit, JSON_THROW_ON_ERROR);
        foreach (['SENTINEL-AUDIT-PAYEE', 'SENTINEL-AUDIT-REFERENCE', 'SENTINEL-AUDIT-DESCRIPTION', 'SENTINEL-AUDIT-NOTE'] as $sentinel) {
            $this->assertStringNotContainsString($sentinel, $serialized);
        }
        foreach (['expense_number', 'expense_category_id', 'category_code_snapshot', 'category_name_snapshot', 'amount', 'payment_method', 'incurred_at', 'recorded_by', 'recorded_by_name_snapshot'] as $field) {
            $this->assertStringContainsString($field, $serialized);
        }
    }

    public function test_lagos_business_date_controls_validation_form_default_and_max(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-04 23:30:00', 'UTC'));
        try {
            $actor = User::factory()->create(['role' => UserRole::Admin]);
            $category = app(CreateExpenseCategory::class)->execute($actor, ['name' => 'Boundary']);
            $this->actingAs($actor)->startSession();
            $response = $this->get(route('expenses.create'))->assertOk()->assertSee('value="2026-09-05"', false)->assertSee('max="2026-09-05"', false);
            preg_match('/name="request_token" value="([^"]+)"/', $response->getContent(), $matches);
            $issued = ExpenseRequest::where('token_hash', hash('sha256', $matches[1]))->firstOrFail();
            $payload = ['request_token' => $matches[1], 'expense_category_id' => $category->id, 'amount' => '10.00', 'payment_method' => 'cash', 'description' => 'Local today', 'incurred_at' => '2026-09-05'];
            $this->withCookie(config('session.cookie'), $issued->session_id)->post(route('expenses.store'), $payload)->assertRedirect();
            $this->assertDatabaseHas('expenses', ['incurred_at' => '2026-09-05']);
            $next = $this->get(route('expenses.create'))->assertOk();
            preg_match('/name="request_token" value="([^"]+)"/', $next->getContent(), $nextMatches);
            $nextIssued = ExpenseRequest::where('token_hash', hash('sha256', $nextMatches[1]))->firstOrFail();
            $this->withCookie(config('session.cookie'), $nextIssued->session_id)->post(route('expenses.store'), array_merge($payload, ['request_token' => $nextMatches[1], 'incurred_at' => '2026-09-06']))->assertSessionHasErrors('incurred_at');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_database_restricts_deleting_expense_and_its_retained_request_while_replay_works(): void
    {
        $actor = User::factory()->create(['role' => UserRole::Manager]);
        $category = app(CreateExpenseCategory::class)->execute($actor, ['name' => 'Deletion Barrier']);
        [$token, $data] = $this->tokenAndData($actor, $category);
        $expense = app(RecordExpense::class)->execute($actor, $data, 'expense-session');
        $request = ExpenseRequest::where('expense_id', $expense->id)->firstOrFail();
        $this->assertSame($request->id, $expense->expense_request_id);
        $this->assertFalse($request->prunable()->pluck('id')->contains($request->id));
        foreach ([fn () => DB::table('expenses')->where('id', $expense->id)->delete(), fn () => DB::table('expense_requests')->where('id', $request->id)->delete()] as $delete) {
            try {
                $delete();
                $this->fail('Raw deletion bypassed relational Expense history protection.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
        $this->assertTrue($expense->is(app(RecordExpense::class)->execute($actor, array_merge($data, ['amount' => '999.00']), 'expense-session')));
        $this->assertDatabaseCount('expenses', 1);
        $this->assertDatabaseCount('expense_requests', 1);
    }

    public function test_request_pruning_preserves_used_and_linked_rows(): void
    {
        $actor = User::factory()->create(['role' => UserRole::Admin]);
        $category = app(CreateExpenseCategory::class)->execute($actor, ['name' => 'Prune']);
        $expense = $this->record($actor, $category);
        $linked = ExpenseRequest::where('expense_id', $expense->id)->firstOrFail();
        $expired = $this->makeToken($actor, 'other', now()->subDay());
        $this->assertTrue(ExpenseRequest::query()->find($expired->id)->prunable()->pluck('id')->contains($expired->id));
        $this->assertFalse(ExpenseRequest::query()->find($linked->id)->prunable()->pluck('id')->contains($linked->id));
    }

    private function record(User $actor, ExpenseCategory $category, array $overrides = []): Expense
    {
        [, $data] = $this->tokenAndData($actor, $category, $overrides);

        return app(RecordExpense::class)->execute($actor, $data, 'expense-session');
    }

    private function tokenAndData(User $actor, ExpenseCategory $category, array $overrides = []): array
    {
        $token = Str::random(64);
        $this->makeToken($actor, $token, now()->addMinutes(30));

        return [$token, array_merge(['request_token' => $token, 'expense_category_id' => $category->id, 'amount' => '100.00', 'payment_method' => 'cash', 'description' => 'Operating expense', 'incurred_at' => now()->subDay()->toDateString()], $overrides)];
    }

    private function makeToken(User $actor, string $token, $expires): ExpenseRequest
    {
        $request = new ExpenseRequest;
        $request->token_hash = hash('sha256', $token);
        $request->actor_id = $actor->id;
        $request->session_id = 'expense-session';
        $request->expires_at = $expires;
        $request->save();

        return $request;
    }
}
