<?php

namespace Tests\Feature\Audit;

use App\Actions\Customer\CreateCustomer;
use App\Actions\Customer\UpdateCustomer;
use App\Actions\Expense\CreateExpenseCategory;
use App\Actions\Expense\RecordExpense;
use App\Actions\Inventory\AdjustStock;
use App\Actions\Inventory\CreateCategory;
use App\Actions\Inventory\CreateProduct;
use App\Actions\Purchase\ReceivePurchase;
use App\Actions\Sale\CreateSale;
use App\Actions\Sale\RecordSalePayment;
use App\Actions\Sale\RecordSaleRefund;
use App\Actions\Sale\RecordSaleReturn;
use App\Actions\Sale\VoidSale;
use App\Actions\Staff\ChangeStaffRole;
use App\Actions\Staff\CreateStaff;
use App\Actions\Staff\DeactivateStaff;
use App\Actions\Supplier\CreateSupplier;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\ExpenseRequest;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\PurchaseRequest;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePaymentRequest;
use App\Models\SaleRefundRequest;
use App\Models\SaleReturnRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\PerPage;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE_SIZE = PerPage::DEFAULT;

    private const BOUNDED_INDEX_QUERIES = 12;

    /* ------------------------------------------------------------ authorization */

    public function test_only_administrators_can_reach_the_audit_trail(): void
    {
        $admin = $this->admin();
        $this->seedOneEvent($admin);
        $event = AuditLog::query()->firstOrFail();

        $this->get(route('audit.index'))->assertRedirect(route('login'));
        $this->get(route('audit.show', $event))->assertRedirect(route('login'));

        foreach ([UserRole::Manager, UserRole::SalesRep] as $role) {
            $user = $this->staff($role);
            $this->actingAs($user)->get(route('audit.index'))->assertForbidden();
            $this->actingAs($user)->get(route('audit.show', $event))->assertForbidden();
        }

        $this->actingAs($admin)->get(route('audit.index'))->assertOk()->assertSee('Audit trail');
        $this->actingAs($admin)->get(route('audit.show', $event))->assertOk();
    }

    public function test_navigation_offers_the_audit_trail_to_administrators_only(): void
    {
        $this->assertStringContainsString('Audit Trail',
            $this->actingAs($this->admin())->get(route('dashboard'))->getContent());

        foreach ([UserRole::Manager, UserRole::SalesRep] as $role) {
            $this->assertStringNotContainsString('Audit Trail',
                $this->actingAs($this->staff($role))->get(route('dashboard'))->getContent());
        }
    }

    public function test_a_known_audit_id_is_not_reachable_by_an_unauthorized_role(): void
    {
        $admin = $this->admin();
        $this->seedOneEvent($admin);
        $ids = AuditLog::query()->pluck('id');
        $this->assertGreaterThan(0, $ids->count());

        foreach ([UserRole::Manager, UserRole::SalesRep] as $role) {
            $user = $this->staff($role);
            foreach ($ids as $id) {
                $this->actingAs($user)->get(route('audit.show', $id))->assertForbidden();
            }
        }
    }

    /* ------------------------------------------------------------- immutability */

    public function test_audit_records_cannot_be_updated_or_deleted_through_the_application(): void
    {
        $admin = $this->admin();
        $this->seedOneEvent($admin);
        $event = AuditLog::query()->firstOrFail();
        $before = DB::table('audit_logs')->where('id', $event->id)->first();

        try {
            $event->action = 'tampered';
            $event->save();
            $this->fail('Audit records must reject updates.');
        } catch (LogicException $exception) {
            $this->assertSame('Audit logs are append-only.', $exception->getMessage());
        }

        try {
            $event->delete();
            $this->fail('Audit records must reject deletion.');
        } catch (LogicException $exception) {
            $this->assertSame('Audit logs are append-only.', $exception->getMessage());
        }

        $this->assertEquals($before, DB::table('audit_logs')->where('id', $event->id)->first());
    }

    public function test_audit_records_reject_mass_assignment_of_attribution(): void
    {
        $this->expectException(MassAssignmentException::class);

        AuditLog::query()->create([
            'action' => 'forged', 'actor_name_snapshot' => 'Somebody Else',
            'auditable_type' => Sale::class, 'auditable_id' => 1,
        ]);
    }

    public function test_no_route_exposes_editing_or_deleting_audit_records(): void
    {
        $auditRoutes = collect(app('router')->getRoutes())
            ->filter(fn ($route) => str_starts_with((string) $route->uri(), 'audit'));

        $this->assertCount(2, $auditRoutes, 'Only the index and detail reads may exist');
        foreach ($auditRoutes as $route) {
            $this->assertSame(['GET', 'HEAD'], array_values(array_diff($route->methods(), ['OPTIONS'])));
        }
    }

    /* ----------------------------------------------------------------- coverage */

    public function test_every_business_module_records_audit_evidence_with_actor_and_subject_snapshots(): void
    {
        $admin = $this->admin();
        $recorded = $this->exerciseEveryModule($admin);

        $expected = [
            'staff_created', 'staff_role_changed', 'staff_deactivated',
            'customer_created', 'customer_updated',
            'category_created', 'product_created', 'stock_adjusted',
            'sale_created', 'sale_initial_payment_recorded', 'sale_payment_recorded', 'sale_voided',
            'sale_return_recorded', 'sale_refund_recorded',
            'supplier_created', 'purchase_received',
            'expense_category_created', 'expense_recorded',
        ];

        foreach ($expected as $action) {
            $this->assertTrue($recorded->has($action), "Missing audit coverage for {$action}");
        }

        foreach (AuditLog::query()->get() as $event) {
            $this->assertNotNull($event->actor_name_snapshot, "{$event->action} lost actor attribution");
            $this->assertNotNull($event->subject_label_snapshot, "{$event->action} lost subject attribution");
            $this->assertNotSame('', trim($event->subject_label_snapshot));
        }

        $this->assertSame($admin->name, $recorded->get('sale_created')->actor_name_snapshot);
        $this->assertSame(UserRole::Admin->value, $recorded->get('sale_created')->actor_role_snapshot);
        $this->assertStringStartsWith('SALE-', $recorded->get('sale_created')->subject_label_snapshot);
        $this->assertStringStartsWith('PAY-', $recorded->get('sale_payment_recorded')->subject_label_snapshot);
        $this->assertStringStartsWith('RET-', $recorded->get('sale_return_recorded')->subject_label_snapshot);
        $this->assertStringStartsWith('REF-', $recorded->get('sale_refund_recorded')->subject_label_snapshot);
        $this->assertStringStartsWith('EXP-', $recorded->get('expense_recorded')->subject_label_snapshot);
        $this->assertStringStartsWith('PUR-', $recorded->get('purchase_received')->subject_label_snapshot);
        $this->assertStringContainsString('CUST-', $recorded->get('customer_created')->subject_label_snapshot);
    }

    public function test_staff_lifecycle_metadata_records_role_and_status_without_credentials(): void
    {
        $admin = $this->admin();
        ['user' => $subject] = app(CreateStaff::class)->execute($admin, [
            'name' => 'New Person', 'email' => 'new.person@example.com', 'phone' => '08030000001',
        ], UserRole::SalesRep);

        app(ChangeStaffRole::class)->execute($admin, $subject->fresh(), UserRole::Manager);
        app(DeactivateStaff::class)->execute($admin, $subject->fresh());

        $created = $this->event('staff_created');
        $this->assertSame(UserRole::SalesRep->value, $created->new_values['role']);
        $this->assertSame('New Person', $created->new_values['name']);

        $roleChange = $this->event('staff_role_changed');
        $this->assertSame(UserRole::SalesRep->value, $roleChange->old_values['role']);
        $this->assertSame(UserRole::Manager->value, $roleChange->new_values['role']);

        $this->assertSame('inactive', $this->event('staff_deactivated')->new_values['status']);

        foreach (AuditLog::query()->where('auditable_type', User::class)->get() as $event) {
            $encoded = json_encode([$event->old_values, $event->new_values, $event->metadata]);
            foreach (['password', 'remember_token', 'pin', 'token', 'secret'] as $forbidden) {
                $this->assertStringNotContainsStringIgnoringCase($forbidden, $encoded,
                    "Staff audit metadata leaked a {$forbidden} field");
            }
        }
    }

    public function test_stock_adjustment_audit_explains_who_without_replacing_the_movement_ledger(): void
    {
        $admin = $this->admin();
        $product = $this->product($admin);
        app(AdjustStock::class)->execute($admin, $product->fresh(), [
            'type' => 'restock', 'operation' => 'increase', 'quantity' => '4', 'reason' => 'Recount',
        ]);

        $event = $this->event('stock_adjusted');
        $this->assertSame('10.000', $event->metadata['quantity_before']);
        $this->assertSame('14.000', $event->metadata['quantity_after']);
        $this->assertSame('restock', $event->metadata['adjustment_type']);
        $this->assertSame('4.000', $event->metadata['quantity_change']);
        $this->assertSame(2, DB::table('inventory_movements')->where('product_id', $product->id)->count(),
            'The opening and adjustment movements stay in inventory_movements');
        $this->assertSame(0, AuditLog::query()->where('auditable_type', InventoryMovement::class)->count(),
            'inventory_movements remains the authoritative stock ledger; audit explains who, it does not duplicate it');
    }

    /* ------------------------------------------------------ snapshots and secrets */

    public function test_snapshots_survive_renaming_and_deletion_of_actor_and_subject(): void
    {
        $admin = $this->admin();
        $actor = $this->staff(UserRole::Manager, 'Original Actor');
        $customer = app(CreateCustomer::class)->execute($actor, $this->customerPayload());
        $event = $this->event('customer_created');
        $label = $event->subject_label_snapshot;

        $actor->forceFill(['name' => 'Renamed Actor'])->save();
        DB::table('customers')->where('id', $customer->id)->update(['first_name' => 'Erased']);
        DB::table('users')->where('id', $actor->id)->delete();

        $fresh = AuditLog::query()->findOrFail($event->id);
        $this->assertSame('Original Actor', $fresh->actor_name_snapshot, 'Actor attribution must survive deletion');
        $this->assertSame(UserRole::Manager->value, $fresh->actor_role_snapshot);
        $this->assertNull($fresh->actor_id, 'The live reference is released, the evidence is not');
        $this->assertSame($label, $fresh->subject_label_snapshot);

        $this->actingAs($admin)->get(route('audit.show', $fresh))->assertOk()
            ->assertSee('Original Actor')->assertDontSee('Renamed Actor');
    }

    public function test_recorded_metadata_never_contains_request_tokens_or_other_secrets(): void
    {
        $admin = $this->admin();
        $this->exerciseEveryModule($admin);

        $forbidden = ['request_token', 'token_hash', 'session_id', 'password', 'remember_token',
            'csrf', 'api_token', 'webhook', 'secret', 'authorization', 'cookie', 'pin'];

        foreach (AuditLog::query()->get() as $event) {
            $encoded = mb_strtolower((string) json_encode([$event->old_values, $event->new_values, $event->metadata]));
            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString($needle, $encoded,
                    "{$event->action} leaked '{$needle}' into audit metadata");
            }
        }
    }

    public function test_update_events_store_only_allowlisted_changed_fields(): void
    {
        $admin = $this->admin();
        $customer = app(CreateCustomer::class)->execute($admin, $this->customerPayload());
        app(UpdateCustomer::class)->execute($admin, $customer->fresh(), [
            'first_name' => 'Renamed', 'last_name' => 'Customer', 'phone' => $customer->phone,
            'email' => null, 'city' => null,
            'address' => 'A private street address', 'notes' => 'PRIVATE CUSTOMER NOTE',
        ]);

        $event = $this->event('customer_updated');
        $encoded = (string) json_encode([$event->old_values, $event->new_values]);
        $this->assertStringNotContainsString('PRIVATE CUSTOMER NOTE', $encoded);
        $this->assertStringNotContainsString('private street address', $encoded);
        $this->assertArrayNotHasKey('address', $event->new_values);
        $this->assertArrayNotHasKey('notes', $event->new_values);
        $this->assertSame('Renamed', $event->new_values['first_name']);
    }

    /* ---------------------------------------------------- atomicity and rollback */

    public function test_a_failing_audit_write_rolls_back_its_business_mutation(): void
    {
        $admin = $this->admin();
        $product = $this->product($admin);
        $customer = app(CreateCustomer::class)->execute($admin, $this->customerPayload());
        $auditRows = DB::table('audit_logs')->count();

        $this->app->bind(AuditLogger::class, fn () => new class extends AuditLogger
        {
            public function record(string $action, Model $auditable, ?User $actor,
                array $oldValues = [], array $newValues = [], array $metadata = [],
                bool $explicitDiff = false): void
            {
                throw new RuntimeException('audit unavailable');
            }
        });

        try {
            app(CreateSale::class)->execute($admin, [
                'customer_id' => $customer->id, 'products' => [['product_id' => $product->id, 'quantity' => '1']],
                'payment_method' => 'cash', 'amount_paid' => '0', 'notes' => null,
            ]);
            $this->fail('The Sale must not commit when its audit evidence cannot be written.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_items', 0);
        $this->assertSame('10.000', $product->fresh()->current_stock, 'Stock must be restored with the rollback');
        $this->assertSame($auditRows, DB::table('audit_logs')->count(), 'No orphan audit row may survive');
    }

    public function test_a_failing_business_mutation_leaves_no_orphan_audit_row(): void
    {
        $admin = $this->admin();
        $product = $this->product($admin);
        $customer = app(CreateCustomer::class)->execute($admin, $this->customerPayload());
        $auditRows = DB::table('audit_logs')->count();

        try {
            app(CreateSale::class)->execute($admin, [
                'customer_id' => $customer->id,
                'products' => [['product_id' => $product->id, 'quantity' => '999']],
                'payment_method' => 'cash', 'amount_paid' => '0', 'notes' => null,
            ]);
            $this->fail('Overselling must fail.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertDatabaseCount('sales', 0);
        $this->assertSame($auditRows, DB::table('audit_logs')->count());
    }

    public function test_replaying_a_consumed_request_token_does_not_duplicate_audit_evidence(): void
    {
        $admin = $this->admin();
        [$sale] = $this->saleWithBalance($admin);

        $token = $this->paymentToken($admin, $sale);
        $payload = ['request_token' => $token, 'amount' => '1000.00', 'payment_method' => 'cash', 'note' => null];

        app(RecordSalePayment::class)->execute($admin, $sale->fresh(), $payload, 'audit-session');
        app(RecordSalePayment::class)->execute($admin, $sale->fresh(), $payload, 'audit-session');

        $this->assertSame(1, DB::table('sale_payments')->where('payment_type', 'settlement')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'sale_payment_recorded')->count(),
            'A replayed request must not create a second audit event');
    }

    /* ---------------------------------------------------------------- read safety */

    public function test_reading_the_audit_trail_and_other_pages_creates_no_audit_events(): void
    {
        $admin = $this->admin();
        $this->exerciseEveryModule($admin);
        $before = ['count' => DB::table('audit_logs')->count(),
            'sha256' => hash('sha256', DB::table('audit_logs')->orderBy('id')->get()->toJson())];

        $event = AuditLog::query()->firstOrFail();
        foreach ([route('audit.index'), route('audit.show', $event), route('dashboard'),
            route('sales.index'), route('reports.sales'), route('audit.index', ['search' => 'SALE'])] as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }

        $this->assertSame($before['count'], DB::table('audit_logs')->count(), 'Reads must not write audit rows');
        $this->assertSame($before['sha256'],
            hash('sha256', DB::table('audit_logs')->orderBy('id')->get()->toJson()));
    }

    /* -------------------------------------------------------- listing behaviour */

    public function test_index_paginates_and_orders_deterministically_when_timestamps_tie(): void
    {
        $admin = $this->admin();
        $ids = $this->seedTiedEvents($admin, 30);

        $page1 = $this->orderedIds($admin, ['page' => 1]);
        $page2 = $this->orderedIds($admin, ['page' => 2]);

        $this->assertCount(self::PAGE_SIZE, $page1, 'The index paginates at the shared default page size');
        $this->assertSame([], array_intersect($page1, $page2), 'Pages must not overlap on tied timestamps');

        $expected = array_reverse($ids);
        $this->assertSame(array_slice($expected, 0, self::PAGE_SIZE), $page1);
        $this->assertSame(array_slice($expected, self::PAGE_SIZE, self::PAGE_SIZE), $page2);
        $this->assertSame($page1, $this->orderedIds($admin, ['page' => 1]), 'Ordering must be stable across renders');
    }

    public function test_filters_narrow_the_trail_to_the_requested_evidence(): void
    {
        $admin = $this->admin();
        $other = $this->staff(UserRole::Manager, 'Other Manager');
        $this->product($admin);
        app(CreateCustomer::class)->execute($other, $this->customerPayload());

        $all = $this->indexContent($admin, []);
        $this->assertStringContainsString('Product Created', $all);
        $this->assertStringContainsString('Customer Created', $all);

        $byActor = $this->indexContent($admin, ['actor' => (string) $other->id]);
        $this->assertStringContainsString('Customer Created', $byActor);
        $this->assertStringNotContainsString('Product Created', $byActor);

        $byEvent = $this->indexContent($admin, ['event' => 'product_created']);
        $this->assertStringContainsString('Product Created', $byEvent);
        $this->assertStringNotContainsString('Customer Created', $byEvent);

        $bySubject = $this->indexContent($admin, ['subject_type' => 'customer']);
        $this->assertStringContainsString('Customer Created', $bySubject);
        $this->assertStringNotContainsString('Product Created', $bySubject);

        $bySearch = $this->indexContent($admin, ['search' => 'AUDIT-SKU']);
        $this->assertStringContainsString('Product Created', $bySearch);
        $this->assertStringNotContainsString('Customer Created', $bySearch);

        $outOfRange = $this->indexContent($admin, ['from' => '2020-01-01', 'to' => '2020-01-31']);
        $this->assertStringContainsString('No audit events match these filters.', $outOfRange);
    }

    public function test_business_timezone_boundaries_include_the_whole_lagos_day(): void
    {
        $admin = $this->admin();
        $this->product($admin);
        $event = $this->event('product_created');

        // 00:05 and 23:55 Lagos on 2026-06-15 are 23:05 on 14 June and 22:55 on 15 June in UTC.
        foreach (['2026-06-14 23:05:00', '2026-06-15 22:55:00'] as $storedUtc) {
            DB::table('audit_logs')->where('id', $event->id)->update(['created_at' => $storedUtc]);
            $this->assertStringContainsString('Product Created',
                $this->indexContent($admin, ['from' => '2026-06-15', 'to' => '2026-06-15']),
                "A Lagos-day event stored at {$storedUtc} UTC must appear in that day's filter");
        }

        DB::table('audit_logs')->where('id', $event->id)->update(['created_at' => '2026-06-15 23:05:00']);
        $this->assertStringContainsString('No audit events match these filters.',
            $this->indexContent($admin, ['from' => '2026-06-15', 'to' => '2026-06-15']),
            'The next Lagos day must not leak into the filter');
    }

    public function test_malformed_and_hostile_filters_never_break_the_audit_trail(): void
    {
        $admin = $this->admin();
        $this->product($admin);

        $probes = [
            'from[]=x&to[]=y', 'actor[]=1', 'event[]=x', 'subject_type[]=x', 'search[]=x',
            'search[a][b]=x', 'from=not-a-date', 'from=2026-13-45', 'from=2026-06-30&to=2026-06-01',
            'actor=99999999999999999999', 'actor=1;DROP TABLE audit_logs', 'event='.urlencode("' OR 1=1 --"),
            'subject_type='.urlencode('App\Models\User'), 'search='.urlencode("%_\\'"),
            'search='.urlencode(str_repeat('a', 2000)), 'search='.urlencode('Ada 🇳🇬 Ọlá'),
            'page=0', 'page=-1', 'page=999999',
        ];

        $before = DB::table('audit_logs')->count();

        foreach ($probes as $query) {
            $response = $this->actingAs($admin)->get(route('audit.index').'?'.$query);
            $this->assertContains($response->getStatusCode(), [200, 302],
                "Probe produced an unexpected status: {$query}");
        }

        $this->assertSame($before, DB::table('audit_logs')->count(), 'Hostile filters must not write anything');
    }

    public function test_audit_pages_escape_hostile_snapshot_and_metadata_values(): void
    {
        $admin = $this->staff(UserRole::Admin, '<script>alert("actor")</script>');
        $hostile = '<img src=x onerror=alert("subject")>';
        app(CreateProduct::class)->execute($admin, [
            'category_id' => ProductCategory::factory()->create()->id,
            'name' => $hostile, 'sku' => 'AUDIT-XSS', 'description' => null,
            'cost_price' => '100.00', 'selling_price' => '200.00',
            'initial_stock' => '1', 'reorder_level' => '1', 'unit' => 'piece', 'is_active' => true,
        ]);

        DB::table('audit_logs')->where('action', 'product_created')
            ->update(['user_agent' => '<script>alert("ua")</script>']);
        $event = $this->event('product_created');

        foreach ([route('audit.index'), route('audit.show', $event)] as $url) {
            $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('<script>alert(', $html);
            $this->assertStringNotContainsString('<img src=x onerror=', $html);
            $this->assertStringContainsString('&lt;', $html);
        }
    }

    public function test_index_and_detail_queries_stay_bounded_as_volume_grows(): void
    {
        $admin = $this->admin();
        $this->seedTiedEvents($admin, 60);
        $small = $this->queryCount($admin, route('audit.index'));

        $this->seedTiedEvents($admin, 300);
        $large = $this->queryCount($admin, route('audit.index'));

        $this->assertSame($small, $large, 'The audit index must not issue more queries as volume grows');
        $this->assertLessThanOrEqual(12, $large, 'The audit index must stay bounded');
        $this->assertLessThanOrEqual(8, $this->queryCount($admin, route('audit.show', AuditLog::query()->firstOrFail())));
        $this->assertGreaterThan(350, DB::table('audit_logs')->count());
    }

    /* ------------------------------------------------------- legacy (pre-snapshot) rows */

    public function test_a_legacy_row_without_snapshots_renders_through_the_live_actor_fallback(): void
    {
        $admin = $this->admin();
        $this->seedLegacyRows($admin, 1);
        $event = AuditLog::query()->firstOrFail();
        $stored = DB::table('audit_logs')->where('id', $event->id)->first();

        $this->assertNull($stored->actor_name_snapshot);
        $this->assertNull($stored->actor_role_snapshot);
        $this->assertNull($stored->subject_label_snapshot);

        foreach ([route('audit.index'), route('audit.show', $event)] as $url) {
            $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();
            $this->assertStringContainsString($admin->name, $html,
                'A legacy row must fall back to the live actor for display');
            $this->assertStringNotContainsString('LazyLoadingViolation', $html);
        }

        $this->assertEquals($stored, DB::table('audit_logs')->where('id', $event->id)->first(),
            'Reading a legacy row must not backfill or otherwise mutate it');
    }

    public function test_a_full_page_of_legacy_rows_stays_bounded_and_never_lazy_loads(): void
    {
        $this->assertTrue(Model::preventsLazyLoading(),
            'This test is only meaningful while strict mode is active');

        $admin = $this->admin();
        $this->seedLegacyRows($admin, self::PAGE_SIZE);
        $before = DB::table('audit_logs')->orderBy('id')->get();

        $onePage = $this->queryCount($admin, route('audit.index'));

        // A second full page of legacy rows must not cost a single extra query.
        $this->seedLegacyRows($admin, self::PAGE_SIZE);
        $twoPages = $this->queryCount($admin, route('audit.index'));

        $this->assertSame($onePage, $twoPages,
            'Legacy rows must not add a query each: the actor fallback is eager-loaded');
        $this->assertLessThanOrEqual(self::BOUNDED_INDEX_QUERIES, $twoPages,
            'The audit index must stay bounded on legacy rows');

        $html = $this->actingAs($admin)->get(route('audit.index'))->assertOk()->getContent();
        $body = $this->tableBody($html);
        $this->assertSame(self::PAGE_SIZE, substr_count($body, 'legacy_event'),
            'The page must render a full page of legacy rows');
        $this->assertSame(self::PAGE_SIZE, mb_substr_count($body, $admin->name),
            'Every legacy row must resolve its actor through the eager-loaded fallback');

        $repeat = $this->actingAs($admin)->get(route('audit.index'))->assertOk()->getContent();
        $this->assertSame($html, $repeat, 'Repeated renders of legacy rows must be identical');

        $this->assertEquals($before, DB::table('audit_logs')->whereIn('id', $before->pluck('id'))->orderBy('id')->get(),
            'Reading legacy rows must leave every audit row byte-identical');
    }

    public function test_a_null_subject_snapshot_renders_without_touching_the_morph_relation(): void
    {
        $admin = $this->admin();
        $this->seedLegacyRows($admin, 5);
        $event = AuditLog::query()->firstOrFail();

        // The subject fallback is a plain scalar and reads auditable_type/auditable_id as columns,
        // so a deleted or absent subject cannot trigger a morph lazy load.
        $this->assertSame(0, DB::table('sales')->where('id', $event->auditable_id)->count());

        foreach ([route('audit.index'), route('audit.show', $event)] as $url) {
            $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('Sale', $html);
            $this->assertStringContainsString('—', $html, 'A missing subject label renders as a dash');
        }

        $this->assertLessThanOrEqual(self::BOUNDED_INDEX_QUERIES, $this->queryCount($admin, route('audit.index')));
    }

    /* ------------------------------------------------------------------ helpers */

    /**
     * Rows as they exist on a database migrated from before snapshots were introduced: a live
     * actor_id, no snapshots, and a subject row that no longer exists.
     */
    private function seedLegacyRows(User $actor, int $count): void
    {
        $rows = [];
        foreach (range(1, $count) as $index) {
            $rows[] = ['actor_id' => $actor->id, 'action' => 'legacy_event',
                'auditable_type' => Sale::class, 'auditable_id' => $index, 'created_at' => now()];
        }
        DB::table('audit_logs')->insert($rows);
    }

    /** The rendered rows only: the filter dropdowns legitimately name every known event. */
    private function tableBody(string $html): string
    {
        $start = mb_strpos($html, '<tbody>');
        $end = mb_strpos($html, '</tbody>');
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        return mb_substr($html, $start, $end - $start);
    }

    private function admin(): User
    {
        return $this->staff(UserRole::Admin, 'Owner Admin');
    }

    private function staff(UserRole $role, ?string $name = null): User
    {
        return User::factory()->create(array_filter(['role' => $role, 'name' => $name]));
    }

    private function event(string $action): AuditLog
    {
        $event = AuditLog::query()->where('action', $action)->latest('id')->first();
        $this->assertNotNull($event, "No audit event recorded for {$action}");

        return $event;
    }

    /** @return array<string, mixed> */
    private function customerPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Audit', 'last_name' => 'Customer', 'phone' => '0803'.random_int(1000000, 9999999),
            'email' => null, 'address' => null, 'city' => null, 'notes' => null, 'whatsapp_opt_in' => false,
        ], $overrides);
    }

    private function product(User $actor, string $sku = 'AUDIT-SKU-1'): Product
    {
        return app(CreateProduct::class)->execute($actor, [
            'category_id' => ProductCategory::factory()->create()->id,
            'name' => 'Audit Product', 'sku' => $sku, 'description' => null,
            'cost_price' => '1000.00', 'selling_price' => '2500.00',
            'initial_stock' => '10', 'reorder_level' => '2', 'unit' => 'piece', 'is_active' => true,
        ]);
    }

    /** @return array{0: Sale, 1: SaleItem} */
    private function saleWithBalance(User $actor): array
    {
        $product = $this->product($actor, 'AUDIT-SKU-'.Str::upper(Str::random(5)));
        $customer = app(CreateCustomer::class)->execute($actor, $this->customerPayload());
        $sale = app(CreateSale::class)->execute($actor, [
            'customer_id' => $customer->id,
            'products' => [['product_id' => $product->id, 'quantity' => '2']],
            'payment_method' => 'cash', 'amount_paid' => '0', 'notes' => null,
        ]);

        return [$sale->fresh(), SaleItem::query()->where('sale_id', $sale->id)->firstOrFail()];
    }

    private function paymentToken(User $actor, Sale $sale): string
    {
        $token = Str::random(64);
        $request = new SalePaymentRequest;
        foreach (['token_hash' => hash('sha256', $token), 'sale_id' => $sale->id, 'actor_id' => $actor->id,
            'session_id' => 'audit-session', 'expires_at' => now()->addMinutes(30)] as $key => $value) {
            $request->$key = $value;
        }
        $request->save();

        return $token;
    }

    private function requestToken(object $request, User $actor, ?Sale $sale = null): string
    {
        $token = Str::random(64);
        foreach (array_filter([
            'token_hash' => hash('sha256', $token), 'sale_id' => $sale?->id, 'actor_id' => $actor->id,
            'session_id' => 'audit-session', 'expires_at' => now()->addMinutes(30),
        ], fn ($value) => $value !== null) as $key => $value) {
            $request->$key = $value;
        }
        $request->save();

        return $token;
    }

    private function seedOneEvent(User $actor): void
    {
        $this->product($actor);
    }

    /**
     * Drives one real mutation through every audited module so coverage, snapshots and secret
     * exclusion are asserted against events the domain actions actually produced.
     *
     * @return Collection<string, AuditLog>
     */
    private function exerciseEveryModule(User $admin): Collection
    {
        ['user' => $subject] = app(CreateStaff::class)->execute($admin, [
            'name' => 'Audited Staff', 'email' => 'audited.staff@example.com', 'phone' => '08030000009',
        ], UserRole::SalesRep);
        app(ChangeStaffRole::class)->execute($admin, $subject->fresh(), UserRole::Manager);
        app(DeactivateStaff::class)->execute($admin, $subject->fresh());

        $customer = app(CreateCustomer::class)->execute($admin, $this->customerPayload());
        app(UpdateCustomer::class)->execute($admin, $customer->fresh(), [
            'first_name' => 'Updated', 'last_name' => 'Customer', 'phone' => $customer->phone,
            'email' => null, 'address' => null, 'city' => null, 'notes' => null,
        ]);

        $category = app(CreateCategory::class)->execute($admin, ['name' => 'Audited Category', 'description' => null]);
        $product = app(CreateProduct::class)->execute($admin, [
            'category_id' => $category->id, 'name' => 'Audited Product', 'sku' => 'AUDIT-SKU-MODULE',
            'description' => null, 'cost_price' => '1000.00', 'selling_price' => '2500.00',
            'initial_stock' => '10', 'reorder_level' => '2', 'unit' => 'piece', 'is_active' => true,
        ]);
        app(AdjustStock::class)->execute($admin, $product->fresh(), [
            'type' => 'restock', 'operation' => 'increase', 'quantity' => '5', 'reason' => 'Opening recount',
        ]);

        $sale = app(CreateSale::class)->execute($admin, [
            'customer_id' => $customer->id,
            'products' => [['product_id' => $product->id, 'quantity' => '2']],
            'payment_method' => 'cash', 'amount_paid' => '1000.00', 'notes' => null,
        ]);
        $item = SaleItem::query()->where('sale_id', $sale->id)->firstOrFail();

        app(RecordSalePayment::class)->execute($admin, $sale->fresh(), [
            'request_token' => $this->paymentToken($admin, $sale), 'amount' => '1000.00',
            'payment_method' => 'cash', 'note' => null,
        ], 'audit-session');

        app(RecordSaleReturn::class)->execute($admin, $sale->fresh(), [
            'request_token' => $this->requestToken(new SaleReturnRequest, $admin, $sale),
            'reason' => 'Audit fixture',
            'items' => [['sale_item_id' => $item->id, 'quantity' => '2.000', 'disposition' => 'non_restock']],
        ], 'audit-session');

        app(RecordSaleRefund::class)->execute($admin, $sale->fresh(), [
            'request_token' => $this->requestToken(new SaleRefundRequest, $admin, $sale),
            'amount' => '500.00', 'payment_method' => 'cash', 'reason' => 'Audit fixture',
        ], 'audit-session');

        $voidable = app(CreateSale::class)->execute($admin, [
            'customer_id' => $customer->id,
            'products' => [['product_id' => $product->id, 'quantity' => '1']],
            'payment_method' => 'cash', 'amount_paid' => '0', 'notes' => null,
        ]);
        app(VoidSale::class)->execute($admin, $voidable->fresh(), 'Audit fixture void');

        $supplier = app(CreateSupplier::class)->execute($admin, ['name' => 'Audit Supplier']);
        app(ReceivePurchase::class)->execute($admin, [
            'request_token' => $this->requestToken(new PurchaseRequest, $admin),
            'supplier_id' => $supplier->id,
            'items' => [['product_id' => $product->id, 'quantity' => '3.000', 'unit_cost' => '900.00']],
        ], 'audit-session');

        $category = app(CreateExpenseCategory::class)->execute($admin, ['name' => 'Audit Expenses']);
        app(RecordExpense::class)->execute($admin, [
            'request_token' => $this->requestToken(new ExpenseRequest, $admin),
            'expense_category_id' => $category->id, 'amount' => '2500.00', 'payment_method' => 'cash',
            'description' => 'Audit fixture expense', 'incurred_at' => now(config('business.timezone'))->toDateString(),
        ], 'audit-session');

        return AuditLog::query()->get()->keyBy('action');
    }

    /** @return list<int> ids in creation order */
    private function seedTiedEvents(User $actor, int $count): array
    {
        $ids = [];
        for ($index = 0; $index < $count; $index++) {
            DB::table('audit_logs')->insert([
                'actor_id' => $actor->id, 'actor_name_snapshot' => $actor->name,
                'actor_role_snapshot' => $actor->role->value, 'action' => 'sale_created',
                'auditable_type' => Sale::class, 'auditable_id' => $index + 1,
                'subject_label_snapshot' => sprintf('SALE-TIE-%04d', $index + 1),
                'created_at' => '2026-06-15 09:00:00',
            ]);
            $ids[] = (int) DB::getPdo()->lastInsertId();
        }

        return $ids;
    }

    /** @return list<int> */
    private function orderedIds(User $admin, array $query): array
    {
        return $this->actingAs($admin)
            ->get(route('audit.index', $query + ['from' => '2026-06-01', 'to' => '2026-06-30']))
            ->assertOk()->viewData('events')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** The rendered table body only: the filter dropdowns legitimately name every known event. */
    private function indexContent(User $admin, array $query): string
    {
        $html = $this->actingAs($admin)
            ->get(route('audit.index', $query + ['from' => '2020-01-01', 'to' => '2030-12-31']))
            ->assertOk()->getContent();
        $start = mb_strpos($html, '<tbody>');
        $end = mb_strpos($html, '</tbody>');
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        return mb_substr($html, $start, $end - $start);
    }

    private function queryCount(User $user, string $url): int
    {
        $count = 0;
        DB::listen(function ($query) use (&$count): void {
            if (! str_contains($query->sql, '`sessions`')) {
                $count++;
            }
        });
        $this->actingAs($user)->get($url)->assertOk();
        DB::getEventDispatcher()->forget(QueryExecuted::class);

        return $count;
    }
}
