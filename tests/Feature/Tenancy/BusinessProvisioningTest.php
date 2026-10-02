<?php

namespace Tests\Feature\Tenancy;

use App\Actions\Business\ProvisionBusiness;
use App\Actions\WhatsAppAutomation\EnsureDefaultAutomations;
use App\Enums\BusinessStatus;
use App\Enums\UserRole;
use App\Models\Business;
use App\Models\Product;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Support\UnavailableIdentifier;
use App\Tenancy\CurrentBusiness;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Concerns\BuildsTransactionWorld;
use Tests\TestCase;

/**
 * A new company becoming a tenant: public signup, the one provisioning service behind it, the
 * empty workspace a new Business starts in, and proof that neither a new Business nor the original
 * installation can reach the other's records.
 */
class BusinessProvisioningTest extends TestCase
{
    use BuildsTransactionWorld, RefreshDatabase;

    private const TENANT_TABLES = ['businesses', 'business_settings', 'users', 'whatsapp_automations', 'business_subscriptions'];

    /** Tables a brand-new Business must have no rows in. */
    private const DOMAIN_TABLES = [
        'products', 'product_categories', 'customers', 'suppliers', 'sales', 'sale_items', 'sale_payments', 'sale_returns',
        'sale_refunds', 'purchases', 'purchase_items', 'expenses', 'expense_categories', 'inventory_movements',
        'whatsapp_messages', 'whatsapp_connection', 'operational_alerts',
    ];

    /* ------------------------------------------------------------------- signup */

    public function test_the_signup_page_asks_for_identity_only(): void
    {
        $html = $this->get(route('register'))->assertOk()->getContent();

        foreach (['owner_name', 'email', 'phone', 'password', 'password_confirmation', 'business_name'] as $field) {
            $this->assertStringContainsString('name="'.$field.'"', $html);
        }

        foreach (['business_id', 'role', 'status', 'plan'] as $field) {
            $this->assertStringNotContainsString('name="'.$field.'"', $html);
        }

        $this->get(route('login'))->assertOk()->assertSee(route('register'))->assertDontSee('Akin Auto Parts');
    }

    public function test_signing_up_provisions_a_business_and_signs_its_owner_in(): void
    {
        $this->get(route('register'));
        $before = session()->getId();

        Notification::fake();
        $this->post(route('register.store'), $this->signup())->assertRedirect(route('verification.notice'));

        $owner = User::query()->where('email', 'ada@obi-auto.test')->sole();
        $business = Business::query()->findOrFail($owner->business_id);
        $this->assertAuthenticatedAs($owner);
        $this->assertNotSame($before, session()->getId(), 'the session is regenerated on sign-in');
        $this->assertSame(['Obi Auto Parts', BusinessStatus::Active], [$business->name, $business->status]);
        $this->assertSame([UserRole::Admin, '+2348035550101', false], [$owner->role, $owner->phone, (bool) $owner->force_password_change]);
        $this->assertNotSame('Secret123', $owner->getRawOriginal('password'), 'the password is hashed');
        $this->assertProvisioned($business);

        // Verification comes first: the Business is unreachable until the emailed link is opened.
        $this->assertTrue($owner->owesEmailVerification());
        $this->get(route('dashboard'))->assertRedirect(route('verification.notice'));
        $link = null;
        Notification::assertSentTo($owner, VerifyEmail::class, function (VerifyEmail $notification) use ($owner, &$link): bool {
            $link = $notification->toMail($owner)->actionUrl;

            return true;
        });
        $this->get($link)->assertRedirect(route('onboarding.pin.edit'));

        // The owner skips the optional quick PIN and lands on their own empty dashboard.
        $this->post(route('onboarding.pin.skip'))->assertRedirect();
        $this->get(route('dashboard'))->assertOk()->assertSee('Set up Obi Auto Parts')->assertSee('Add your first product');
    }

    public function test_signup_cannot_choose_a_role_status_or_business(): void
    {
        $this->post(route('register.store'), $this->signup([
            'role' => 'manager', 'business_id' => Business::query()->value('id'), 'status' => 'suspended', 'plan' => 'enterprise',
        ]))->assertSessionHasErrors(['role', 'business_id', 'status', 'plan']);

        $this->post(route('register.store'), $this->signup(['owner_name' => ['Ada'], 'business_name' => ['x' => 'y']]))
            ->assertSessionHasErrors(['owner_name', 'business_name']);

        $this->assertSame(1, Business::query()->count());
        $this->assertGuest();
    }

    public function test_an_unavailable_identifier_is_refused_without_naming_its_holder(): void
    {
        $existing = User::factory()->create(['email' => 'taken@alpha.test', 'phone' => '+2348035550199']);

        $this->post(route('register.store'), $this->signup(['email' => 'TAKEN@alpha.test ']))
            ->assertSessionHasErrors(['email' => UnavailableIdentifier::EMAIL]);
        $this->post(route('register.store'), $this->signup(['phone' => '0803 555 0199']))
            ->assertSessionHasErrors(['phone' => UnavailableIdentifier::PHONE]);

        $this->assertSame(1, Business::query()->count(), 'no Business is created for a refused signup');
        $this->assertSame($existing->business_id, User::query()->where('email', 'taken@alpha.test')->value('business_id'));
    }

    public function test_signup_is_throttled(): void
    {
        $statuses = [];

        for ($i = 0; $i < 5; $i++) {
            $statuses[] = $this->post(route('register.store'), $this->signup(['email' => "owner{$i}@throttle.test", 'phone' => '0803555010'.$i]))->getStatusCode();
            $this->post(route('logout'));
        }

        $this->assertContains(429, $statuses);
    }

    public function test_a_suspended_business_is_still_refused_after_signup(): void
    {
        $owner = $this->provision('Suspend Me', 'owner@suspend.test', '08035550150');
        Business::query()->whereKey($owner->business_id)->update(['status' => BusinessStatus::Suspended->value]);

        $this->actingAs($owner->fresh())->get(route('dashboard'))->assertRedirect(route('login'));
    }

    /* ---------------------------------------------------------------- provisioning */

    public function test_two_businesses_are_provisioned_identically_and_share_nothing(): void
    {
        $installation = Business::query()->orderBy('id')->firstOrFail();
        $before = $this->snapshotOf($installation);

        $ownerA = $this->provision('Obi Auto Parts', 'ada@obi.test', '08035550101');
        $ownerB = $this->provision('Obi Auto Parts', 'bola@other.test', '08035550102');

        $this->assertNotSame($ownerA->business_id, $ownerB->business_id, 'identical names are two different tenants');

        foreach ([$ownerA, $ownerB] as $owner) {
            $business = Business::query()->findOrFail($owner->business_id);
            $this->assertSame(UserRole::Admin, $owner->role);
            $this->assertProvisioned($business);
        }

        $this->assertSame($before, $this->snapshotOf($installation), 'the original Business is untouched');
    }

    public function test_a_failure_at_any_step_leaves_no_trace(): void
    {
        $counts = $this->counts();

        // After the Business: its settings row violates the non-blank name CHECK.
        $this->assertProvisioningFails(fn () => $this->provision('   ', 'blank@fail.test', '08035550160'));
        $this->assertSame($counts, $this->counts());

        // After the Business and settings: the owner's email is already taken.
        User::factory()->create(['email' => 'dupe@fail.test']);
        $counts = $this->counts();
        $this->assertProvisioningFails(fn () => $this->provision('Dupe Ltd', 'dupe@fail.test', '08035550161'));
        $this->assertSame($counts, $this->counts());

        // After the Business, settings and owner: the defaults cannot be written.
        $this->app->instance(EnsureDefaultAutomations::class, new class extends EnsureDefaultAutomations
        {
            public function for(Business $business): int
            {
                throw new RuntimeException('defaults unavailable');
            }
        });
        $this->assertProvisioningFails(fn () => $this->provision('Late Ltd', 'late@fail.test', '08035550162'));
        $this->assertSame($counts, $this->counts());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'business_provisioned')->count());
    }

    public function test_losing_a_signup_race_leaves_no_business_and_a_neutral_answer(): void
    {
        $counts = $this->counts();

        // Validation passes; then, before the owner is inserted, another signup takes the email.
        User::creating(function (User $user): void {
            if ($user->email === 'race@signup.test' && ! User::query()->where('email', 'race@signup.test')->exists()) {
                DB::table('users')->insert([
                    'business_id' => Business::query()->orderBy('id')->value('id'), 'name' => 'Winner', 'email' => 'race@signup.test',
                    'password' => bcrypt('Secret123'), 'role' => 'admin', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        $this->post(route('register.store'), $this->signup(['email' => 'race@signup.test']))
            ->assertSessionHasErrors(['email' => UnavailableIdentifier::EITHER]);

        $this->assertGuest();
        $this->assertSame($counts, $this->counts(), 'the loser leaves no Business, settings, owner or automations');
    }

    /* ---------------------------------------------------------------- first run */

    public function test_a_new_business_starts_empty_and_every_screen_renders(): void
    {
        $alpha = $this->worldInInstallation();
        $owner = $this->readyOwner($this->provision('Bravo Hardware', 'bola@bravo.test', '08035550170'));

        foreach (self::DOMAIN_TABLES as $table) {
            $this->assertSame(0, DB::table($table)->where('business_id', $owner->business_id)->count(), $table);
        }

        foreach ([
            'dashboard', 'operations', 'audit.index', 'customers.index', 'customers.create', 'expense-categories.index', 'expense-categories.create',
            'expenses.index', 'expenses.create', 'inventory.index', 'inventory.categories.index', 'inventory.categories.create',
            'inventory.products.create', 'notifications.index', 'profile.edit', 'purchases.index', 'purchases.create', 'refunds.index',
            'reports.index', 'reports.summary', 'reports.collections', 'reports.customers', 'reports.expenses', 'reports.inventory',
            'reports.products', 'reports.purchases', 'reports.receivables', 'reports.sales', 'reports.staff', 'returns.index',
            'discounts.index', 'sale-payments.index', 'sales.index', 'sales.create', 'settings.business.edit', 'staff.index',
            'staff.create', 'suppliers.index', 'suppliers.create', 'whatsapp.automation.index', 'whatsapp.logs.index',
            'customers.export', 'sales.export',
        ] as $route) {
            $response = $this->actingAs($owner)->get(route($route));
            $this->assertSame(200, $response->getStatusCode(), "{$route} must render for an empty Business");
            $body = $response->baseResponse instanceof StreamedResponse ? $response->streamedContent() : $response->getContent();

            foreach ([$alpha['customer']->first_name, $alpha['supplier']->name, 'Ada Rent', 'Ada fuel', $alpha['sale']->sale_number] as $leak) {
                $this->assertStringNotContainsString($leak, (string) $body, "{$route} must not show another Business's data");
            }
        }
    }

    public function test_the_setup_checklist_follows_the_businesss_own_records(): void
    {
        $this->worldInInstallation();
        $owner = $this->readyOwner($this->provision('Bravo Hardware', 'bola@bravo.test', '08035550171'));

        // The installation's products, customers and staff count for nothing here.
        $this->actingAs($owner)->get(route('dashboard'))->assertOk()
            ->assertViewHas('setup', fn (array $steps): bool => collect($steps)->where('done', true)->isEmpty());

        app(CurrentBusiness::class)->run(Business::query()->findOrFail($owner->business_id), function () use ($owner): void {
            Product::factory()->forBusiness($owner->business)->create();
        });

        $this->actingAs($owner)->get(route('dashboard'))
            ->assertViewHas('setup', fn (array $steps): bool => collect($steps)->firstWhere('key', 'product')['done'] === true
                && collect($steps)->firstWhere('key', 'customer')['done'] === false);

        $manager = User::factory()->forBusiness($owner->business)->create(['role' => UserRole::Manager, 'quick_pin_setup_completed' => true]);
        $this->actingAs($manager)->get(route('dashboard'))->assertOk()->assertViewHas('setup', null);
    }

    /* ------------------------------------------------------------ cross-tenant */

    public function test_a_provisioned_business_cannot_reach_the_installations_records(): void
    {
        $alpha = $this->worldInInstallation();
        $owner = $this->readyOwner($this->provision('Bravo Hardware', 'bola@bravo.test', '08035550172'));
        $audit = DB::table('audit_logs')->where('business_id', $alpha['sale']->business_id)->value('id');
        $message = $this->installationMessage($alpha);

        foreach ([
            ['customers.show', [$alpha['customer']]], ['customers.edit', [$alpha['customer']]], ['customers.activity', [$alpha['customer']]],
            ['suppliers.show', [$alpha['supplier']]], ['suppliers.edit', [$alpha['supplier']]],
            ['inventory.products.show', [$alpha['product']]], ['inventory.products.edit', [$alpha['product']]],
            ['inventory.products.movements', [$alpha['product']]], ['inventory.categories.edit', [$alpha['product']->category_id]],
            ['expense-categories.show', [$alpha['expenseCategory']]], ['expenses.show', [$alpha['expense']]], ['expenses.receipt', [$alpha['expense']]],
            ['purchases.show', [$alpha['purchase']]], ['purchases.receipt', [$alpha['purchase']]],
            ['sales.show', [$alpha['sale']]], ['sales.receipt', [$alpha['sale']]], ['sales.lines', [$alpha['sale']]], ['sales.activity', [$alpha['sale']]],
            ['sales.payments.show', [$alpha['sale'], $alpha['payment']]], ['sales.returns.create', [$alpha['paidSale']]],
            ['sales.refunds.create', [$alpha['paidSale']]], ['returns.show', [$alpha['return']]], ['refunds.show', [$alpha['refund']]],
            ['discounts.show', [$alpha['draft']]], ['staff.show', [$alpha['admin']]], ['staff.edit', [$alpha['admin']]],
            ['users.photo', [$alpha['admin']]], ['audit.show', [$audit]],
        ] as [$route, $parameters]) {
            $this->actingAs($owner)->get(route($route, $parameters))->assertNotFound();
        }

        $this->actingAs($owner)->post(route('whatsapp.messages.retry', $message))->assertNotFound();
        $this->actingAs($owner)->put(route('customers.update', $alpha['customer']), ['first_name' => 'Hijacked'])->assertNotFound();
        $this->assertNotSame('Hijacked', DB::table('customers')->where('id', $alpha['customer']->id)->value('first_name'));

        // And in reverse: the installation cannot reach the new Business.
        $staff = User::factory()->forBusiness($owner->business)->create(['role' => UserRole::Manager]);
        $this->actingAs($this->readyOwner($alpha['admin']))->get(route('staff.show', $staff))->assertNotFound();
    }

    /* -------------------------------------------------------------- administration */

    public function test_create_admin_needs_an_explicit_business_and_never_adds_a_second_administrator(): void
    {
        $owner = $this->provision('Bravo Hardware', 'bola@bravo.test', '08035550173');
        $empty = Business::factory()->create(['name' => 'Recovered Ltd']);

        $this->artisan('inventra:create-admin')->expectsOutput('More than one business exists. Name the business with --business=<id>.')->assertFailed();
        $this->artisan('inventra:create-admin', ['--business' => (string) $owner->business_id])
            ->expectsOutput('An administrator already exists.')->assertFailed();

        $this->artisan('inventra:create-admin', ['--business' => (string) $empty->id])
            ->expectsQuestion('Name', 'Recovered Admin')
            ->expectsQuestion('Email address', 'admin@recovered.test')
            ->expectsQuestion('Phone number (optional)', '')
            ->expectsQuestion('Password', 'Recover123')
            ->expectsQuestion('Confirm password', 'Recover123')
            ->expectsOutput("Administrator created for business #{$empty->id}.")
            ->assertSuccessful();

        $this->assertSame($empty->id, User::query()->where('email', 'admin@recovered.test')->value('business_id'));
    }

    /* ------------------------------------------------------------------- helpers */

    /** @param array<string, mixed> $overrides */
    private function signup(array $overrides = []): array
    {
        return array_merge([
            'owner_name' => 'Ada Obi', 'email' => 'ada@obi-auto.test', 'phone' => '0803 555 0101',
            'password' => 'Secret123', 'password_confirmation' => 'Secret123', 'business_name' => 'Obi Auto Parts',
        ], $overrides);
    }

    private function provision(string $business, string $email, string $phone): User
    {
        // Provisioning runs with no signed-in user and no Business in context, as signup does.
        app(CurrentBusiness::class)->forget();

        try {
            return app(ProvisionBusiness::class)->execute([
                'business_name' => $business, 'owner_name' => 'Owner of '.$business, 'email' => $email,
                'phone' => User::normalizePhone($phone), 'password' => 'Secret123',
            ]);
        } finally {
            app(CurrentBusiness::class)->set(Business::query()->orderBy('id')->firstOrFail());
        }
    }

    private function readyOwner(User $owner): User
    {
        $owner->forceFill(['quick_pin_setup_completed' => true, 'email_verified_at' => now()])->save();

        return $owner->fresh();
    }

    private function assertProvisioned(Business $business): void
    {
        $this->assertSame(1, DB::table('business_settings')->where('business_id', $business->id)->count());
        $settings = DB::table('business_settings')->where('business_id', $business->id)->first();
        $this->assertSame([$business->name, 'NGN', null, null, null, null], [$settings->business_name, $settings->currency,
            $settings->business_phone, $settings->business_address, $settings->logo_path, $settings->manager_alert_number]);
        $this->assertSame(['low_stock', 'pickup_reminder', 'post_purchase', 'welcome'],
            DB::table('whatsapp_automations')->where('business_id', $business->id)->orderBy('key')->pluck('key')->all());
        $this->assertSame(0, DB::table('whatsapp_automations')->where('business_id', $business->id)->where('enabled', true)->count());
        $this->assertSame([UserRole::Admin->value], DB::table('users')->where('business_id', $business->id)->pluck('role')->all());
        $subscription = DB::table('business_subscriptions as s')->join('plans as p', 'p.id', '=', 's.plan_id')
            ->where('s.business_id', $business->id)->sole(['s.status', 'p.key', 's.trial_starts_at', 's.trial_ends_at']);
        $this->assertSame(['trialing', config('plans.default')], [$subscription->status, $subscription->key], 'a new Business starts its trial on the default plan');
        $this->assertNotNull($subscription->trial_ends_at);
        $this->assertSame($business->id, DB::table('audit_logs')->where('action', 'business_provisioned')->where('business_id', $business->id)->value('business_id'));

        foreach (self::DOMAIN_TABLES as $table) {
            $this->assertSame(0, DB::table($table)->where('business_id', $business->id)->count(), "{$table} must start empty");
        }
    }

    private function assertProvisioningFails(\Closure $attempt): void
    {
        try {
            $attempt();
            $this->fail('Provisioning must fail here.');
        } catch (UniqueConstraintViolationException|QueryException|RuntimeException) {
            $this->addToAssertionCount(1);
        }
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        return collect(self::TENANT_TABLES)->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
    }

    /** @return array<string, mixed> */
    private function snapshotOf(Business $business): array
    {
        return [
            (array) DB::table('businesses')->where('id', $business->id)->first(),
            (array) DB::table('business_settings')->where('business_id', $business->id)->first(),
            DB::table('whatsapp_automations')->where('business_id', $business->id)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function worldInInstallation(): array
    {
        return $this->world(Business::query()->orderBy('id')->firstOrFail(), 'Ada', '50.00');
    }

    private function installationMessage(array $alpha): WhatsAppMessage
    {
        $message = new WhatsAppMessage;
        $message->forceFill([
            'business_id' => $alpha['customer']->business_id, 'customer_id' => $alpha['customer']->id, 'type' => 'welcome',
            'recipient_name' => 'Ada', 'destination_phone' => '+2348031234567', 'body' => 'Hi', 'idempotency_key' => 'x:'.Str::uuid(),
            'origin' => 'automatic', 'status' => 'failed', 'queued_at' => now(), 'attempt' => 1,
        ])->save();

        return $message;
    }
}
