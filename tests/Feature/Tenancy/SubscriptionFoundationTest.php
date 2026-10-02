<?php

namespace Tests\Feature\Tenancy;

use App\Actions\Business\ProvisionBusiness;
use App\Actions\WhatsAppAutomation\WhatsAppAutomationTriggers;
use App\Contracts\WhatsAppConnectionProvider;
use App\Enums\BusinessStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\Business;
use App\Models\BusinessSubscription;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Services\AuditLogger;
use App\Subscriptions\Access;
use App\Subscriptions\Entitlement;
use App\Subscriptions\Entitlements;
use App\Subscriptions\SubscriptionAccess;
use App\Subscriptions\SubscriptionLifecycle;
use App\Support\WhatsApp\OnboardingFailure;
use App\Tenancy\CurrentBusiness;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Tests\Concerns\BuildsWhatsAppWorld;
use Tests\Fakes\FakeWhatsAppProvider;
use Tests\TestCase;

/**
 * Plans, trials, subscriptions, entitlements and email verification — across two Businesses with
 * different plans and states, and with the clock moved rather than waited on.
 */
class SubscriptionFoundationTest extends TestCase
{
    use BuildsWhatsAppWorld, RefreshDatabase;

    private const START = '2026-10-01 09:00:00';

    /* -------------------------------------------------------------------- plans */

    public function test_plans_are_platform_data_kept_in_step_with_config_by_key(): void
    {
        $this->assertFalse(Schema::hasColumn('plans', 'business_id'), 'plans are deliberately global');
        $this->assertTrue(Schema::hasColumn('business_subscriptions', 'business_id'));
        $this->assertSame(['legacy', 'standard'], Plan::query()->orderBy('key')->pluck('key')->all());

        config(['plans.definitions.standard.trial_days' => 21, 'plans.definitions.pilot' => [
            'name' => 'Pilot', 'is_active' => true, 'price_minor' => 1500000, 'billing_interval' => 'month', 'trial_days' => 7,
            'entitlements' => ['max_managers' => 5, 'max_sales_representatives' => 2, 'max_products' => 50, 'whatsapp_automation' => false],
        ]]);
        $this->artisan('inventra:sync-plans')->assertSuccessful();
        $this->artisan('inventra:sync-plans')->assertSuccessful();

        $this->assertSame(3, Plan::query()->count(), 'idempotent by key');
        $this->assertSame(21, Plan::byKey('standard')->trial_days);
        $this->assertSame([1500000, 5, false], [Plan::byKey('pilot')->price_minor, Plan::byKey('pilot')->grant(Entitlement::MaxManagers), Plan::byKey('pilot')->grant(Entitlement::WhatsAppAutomation)]);

        config(['plans.definitions.broken' => ['name' => 'Broken', 'is_active' => true, 'price_minor' => 9.99, 'billing_interval' => 'month', 'trial_days' => 0, 'entitlements' => []]]);
        $this->expectException(RuntimeException::class);
        $this->artisan('inventra:sync-plans');
    }

    public function test_the_existing_business_keeps_full_access_and_its_users_are_not_locked_out(): void
    {
        $installation = Business::query()->orderBy('id')->firstOrFail();
        $subscription = BusinessSubscription::forBusiness($installation);

        $this->assertSame(['legacy', SubscriptionStatus::Active, null], [$subscription->plan->key, $subscription->status, $subscription->current_period_ends_at]);
        $this->assertSame(Access::Full, app(SubscriptionAccess::class)->for($installation));

        $admin = User::factory()->create(['role' => UserRole::Admin, 'quick_pin_setup_completed' => true]);
        $this->assertFalse($admin->owesEmailVerification(), 'existing accounts are never required to verify');
        $this->actingAs($admin)->get(route('dashboard'))->assertOk();
    }

    /* --------------------------------------------------------------- trial clock */

    public function test_a_trial_runs_to_the_second_then_grace_then_read_only(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::START));
        config(['plans.grace_days' => 7]);
        $business = $this->provision('Clock Ltd', 'clock@test.test', '08035550201')->business;
        $trialEnd = CarbonImmutable::parse(self::START)->addDays(14);
        $graceEnd = $trialEnd->addDays(7);
        $access = app(SubscriptionAccess::class);

        $this->assertSame(Access::Full, $access->for($business, $trialEnd->subSecond()));
        $this->assertSame(Access::Grace, $access->for($business, $trialEnd), 'the trial is over at its end instant');
        $this->assertSame(Access::Grace, $access->for($business, $graceEnd->subSecond()));
        $this->assertSame(Access::Restricted, $access->for($business, $graceEnd));

        // Access never waits for the scheduler; the scheduler records what already happened.
        $this->travelTo($graceEnd->addHour());
        $this->assertSame(Access::Restricted, $access->for($business));
        $this->artisan('inventra:advance-subscriptions')->assertSuccessful();
        $this->artisan('inventra:advance-subscriptions')->assertSuccessful();

        $stored = BusinessSubscription::forBusiness($business);
        $this->assertSame(SubscriptionStatus::Suspended, $stored->status);
        $this->assertTrue($stored->grace_ends_at->equalTo($graceEnd));
        $this->assertSame(['trial_started', 'subscription_entered_grace', 'subscription_suspended'],
            DB::table('audit_logs')->where('business_id', $business->id)->where('auditable_type', BusinessSubscription::class)->orderBy('id')->pluck('action')->all(),
            'each transition audited exactly once');
    }

    public function test_activation_restores_full_access_and_a_period_end_leads_to_grace(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::START));
        $business = $this->provision('Paid Ltd', 'paid@test.test', '08035550202')->business;
        $lifecycle = app(SubscriptionLifecycle::class);

        $this->travelTo(CarbonImmutable::parse(self::START)->addDays(60));
        $lifecycle->advance($business);
        $this->assertSame(Access::Restricted, app(SubscriptionAccess::class)->for($business));

        $periodEnd = CarbonImmutable::now()->addMonth();
        $lifecycle->activate($business, $periodEnd);
        $this->assertSame(Access::Full, app(SubscriptionAccess::class)->for($business));
        $this->assertSame(Access::Grace, app(SubscriptionAccess::class)->for($business, $periodEnd));
        $this->assertSame(1, DB::table('audit_logs')->where('business_id', $business->id)->where('action', 'subscription_reactivated')->count());

        $lifecycle->changePlan($business, Plan::byKey('legacy'));
        $this->assertSame(['plan_key' => 'legacy'], json_decode(DB::table('audit_logs')->where('business_id', $business->id)->where('action', 'plan_changed')->value('new_values'), true));
    }

    /* ------------------------------------------------------------ restriction */

    public function test_a_restricted_business_is_read_only_and_keeps_all_its_data(): void
    {
        [$ownerA, $ownerB] = [$this->ready($this->provision('Alpha Ltd', 'alpha@test.test', '08035550203')), $this->ready($this->provision('Bravo Ltd', 'bravo@test.test', '08035550204'))];
        $customer = $this->inBusiness($ownerA->business, fn () => Customer::factory()->forBusiness($ownerA->business)->create(['first_name' => 'Alpha', 'last_name' => 'Buyer']));
        $this->suspend($ownerA->business);

        // Everything readable, including exports.
        foreach (['dashboard', 'customers.index', 'customers.export', 'reports.sales', 'subscription.show'] as $route) {
            $this->actingAs($ownerA)->get(route($route))->assertOk();
        }
        $this->actingAs($ownerA)->get(route('customers.show', $customer))->assertOk()->assertSee('Alpha');
        $this->actingAs($ownerA)->get(route('dashboard'))->assertSee('read-only');

        // Nothing changes.
        $this->actingAs($ownerA)->post(route('customers.store'), ['first_name' => 'New', 'last_name' => 'One', 'phone' => '08039990001'])
            ->assertRedirect(route('subscription.show'));
        $this->actingAs($ownerA)->postJson(route('staff.store'), ['name' => 'x', 'email' => 'x@alpha.test', 'role' => 'manager'])->assertForbidden();
        $this->assertSame(1, DB::table('customers')->where('business_id', $ownerA->business_id)->count());

        // The operator's own account is not Business data.
        $this->actingAs($ownerA)->put(route('profile.update'), ['name' => 'Renamed Owner', 'email' => $ownerA->email, 'phone' => '08035550203'])->assertRedirect();
        $this->assertSame('Renamed Owner', $ownerA->fresh()->name);

        // B is unaffected by A's restriction.
        $this->actingAs($ownerB)->post(route('customers.store'), ['first_name' => 'Bravo', 'last_name' => 'Buyer', 'phone' => '08039990002'])->assertRedirect();
        $this->assertSame(1, DB::table('customers')->where('business_id', $ownerB->business_id)->count());
        $this->actingAs($ownerB)->get(route('dashboard'))->assertDontSee('read-only');
    }

    public function test_a_platform_suspension_outranks_any_subscription(): void
    {
        $owner = $this->ready($this->provision('Paused Ltd', 'paused@test.test', '08035550205'));
        Business::query()->whereKey($owner->business_id)->update(['status' => BusinessStatus::Suspended->value]);

        $this->assertSame(Access::Full, app(SubscriptionAccess::class)->for($owner->business_id), 'commercially fine');
        $this->actingAs($owner)->get(route('dashboard'))->assertRedirect(route('login'));
    }

    /* ------------------------------------------------------ email verification */

    public function test_an_unverified_owner_reaches_only_verification_and_sign_out(): void
    {
        Notification::fake();
        $owner = $this->provision('Unverified Ltd', 'owner@unverified.test', '08035550206');
        $owner->forceFill(['quick_pin_setup_completed' => true])->save();

        foreach (['dashboard', 'customers.index', 'subscription.show', 'profile.edit'] as $route) {
            $this->actingAs($owner)->get(route($route))->assertRedirect(route('verification.notice'));
        }
        $this->actingAs($owner)->get(route('verification.notice'))->assertOk()->assertSee('owner@unverified.test');
        $this->actingAs($owner)->post(route('verification.send'))->assertRedirect();
        Notification::assertSentTo($owner, VerifyEmail::class);
        $this->actingAs($owner)->post(route('logout'))->assertRedirect(route('login'));
    }

    public function test_a_verification_link_cannot_be_forged_reused_elsewhere_or_used_after_expiry(): void
    {
        $owner = $this->provision('Verify Ltd', 'owner@verify.test', '08035550207');
        $other = $this->provision('Other Ltd', 'owner@other.test', '08035550208');
        $link = fn (User $user, int $minutes = 60) => URL::temporarySignedRoute('verification.verify', now()->addMinutes($minutes), ['id' => $user->id, 'hash' => sha1($user->email)]);

        // Another account's valid link, a tampered signature, and an expired link.
        $this->actingAs($owner)->get($link($other))->assertForbidden();
        $this->actingAs($owner)->get($link($owner).'x')->assertForbidden();
        $expired = $link($owner, 1);
        $this->travel(2)->minutes();
        $this->actingAs($owner)->get($expired)->assertForbidden();
        $this->assertFalse($owner->fresh()->hasVerifiedEmail());
        $this->assertFalse($other->fresh()->hasVerifiedEmail());

        // Its own link verifies it, once; opening it again is harmless.
        $this->actingAs($owner)->get($link($owner))->assertRedirect();
        $verifiedAt = $owner->fresh()->email_verified_at;
        $this->actingAs($owner)->get($link($owner))->assertRedirect();
        $this->assertTrue($verifiedAt->equalTo($owner->fresh()->email_verified_at));
        $this->assertSame(1, DB::table('security_events')->where('event', 'email_verified')->where('subject_user_id', $owner->id)->count());
    }

    public function test_resending_the_link_is_throttled(): void
    {
        Notification::fake();
        $owner = $this->provision('Resend Ltd', 'owner@resend.test', '08035550209');
        $statuses = [];

        for ($i = 0; $i < 3; $i++) {
            $statuses[] = $this->actingAs($owner)->post(route('verification.send'))->getStatusCode();
        }

        $this->assertSame([302, 302, 429], $statuses);
        Notification::assertSentToTimes($owner, VerifyEmail::class, 2);
    }

    public function test_signup_cannot_choose_its_plan_state_or_trial(): void
    {
        $this->post(route('register.store'), [
            'owner_name' => 'Ada Obi', 'email' => 'ada@plan.test', 'phone' => '08035550210', 'password' => 'Secret123',
            'password_confirmation' => 'Secret123', 'business_name' => 'Plan Ltd', 'plan_id' => 1, 'plan' => 'legacy',
            'subscription_status' => 'active', 'trial_ends_at' => '2099-01-01', 'business_status' => 'active', 'email_verified_at' => now(),
        ])->assertSessionHasErrors(['plan_id', 'plan', 'subscription_status', 'trial_ends_at', 'business_status', 'email_verified_at']);

        $this->assertSame(0, User::query()->where('email', 'ada@plan.test')->count());
    }

    /* ------------------------------------------------------------ whatsapp entitlement */

    public function test_whatsapp_follows_each_businesss_own_entitlement_in_the_browser_and_the_scheduler(): void
    {
        $provider = new FakeWhatsAppProvider;
        $this->app->instance(WhatsAppConnectionProvider::class, $provider);
        $ownerA = $this->ready($this->provision('Alpha Ltd', 'alpha@wa.test', '08035550213'));
        $ownerB = $this->ready($this->provision('Bravo Ltd', 'bravo@wa.test', '08035550214'));
        $this->connectWhatsApp($ownerA->business, '100000000000001', '200000000000001', 'TOKEN_A');
        $this->connectWhatsApp($ownerB->business, '100000000000002', '200000000000002', 'TOKEN_B');
        $welcomeA = $this->approvedAutomation($ownerA->business);
        $welcomeB = $this->approvedAutomation($ownerB->business);
        $queuedA = $this->queuedMessage(WhatsAppConnection::forBusiness($ownerA->business), $welcomeA, 'ent:a');
        $queuedB = $this->queuedMessage(WhatsAppConnection::forBusiness($ownerB->business), $welcomeB, 'ent:b');

        // A's plan drops WhatsApp.
        $this->onPlan($ownerA->business, 'no_whatsapp', ['whatsapp_automation' => false]);

        $this->actingAs($ownerA)->postJson(route('whatsapp.automation.connection.start'))->assertUnprocessable()
            ->assertJsonPath('errors.connection.0', OnboardingFailure::NotEntitled->message());
        $this->actingAs($ownerA)->postJson(route('whatsapp.automation.test', $welcomeA))->assertUnprocessable();
        auth()->forgetGuards();

        // No new message is queued for A, and the one already queued is not sent.
        $customerA = $this->inBusiness($ownerA->business, fn () => Customer::factory()->forBusiness($ownerA->business)->create(['is_active' => true, 'whatsapp_opt_in' => true, 'whatsapp_opt_in_at' => now()]));
        app(WhatsAppAutomationTriggers::class)->customerCreated($customerA);
        $this->assertSame(0, DB::table('whatsapp_messages')->where('customer_id', $customerA->id)->count());

        $this->artisan('inventra:dispatch-whatsapp-messages')->assertSuccessful();
        $this->assertSame(['failed', 'not_entitled'], array_values((array) DB::table('whatsapp_messages')->where('id', $queuedA->id)->first(['status', 'failure_code'])));
        $this->assertSame(['TOKEN_B'], array_column($provider->sent, 'token'), 'B, still entitled, sends');
        $this->assertSame('sent', DB::table('whatsapp_messages')->where('id', $queuedB->id)->value('status'));

        // A subscription restriction stops paid sending too, whatever the plan grants.
        $this->suspend($ownerB->business);
        $this->assertFalse(app(Entitlements::class)->allows($ownerB->business, Entitlement::WhatsAppAutomation));
        $this->assertTrue(Plan::query()->find(BusinessSubscription::forBusiness($ownerB->business)->plan_id)->grant(Entitlement::WhatsAppAutomation));
    }

    /* ------------------------------------------------------------- subscription page */

    public function test_the_subscription_page_shows_only_the_administrators_own_business(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::START));
        $ownerA = $this->ready($this->provision('Alpha Ltd', 'alpha@page.test', '08035550215'));
        $ownerB = $this->ready($this->provision('Bravo Ltd', 'bravo@page.test', '08035550216'));
        $this->onPlan($ownerB->business, 'bravo_secret_plan', ['max_managers' => 9, 'whatsapp_automation' => false]);

        $this->actingAs($ownerA)->get(route('subscription.show'))->assertOk()
            ->assertSee('Standard')->assertSee('Free trial')->assertSee('Managers')->assertSee('0 / 1')->assertSee('0 / 20')->assertSee('Billing setup is coming soon')
            ->assertDontSee('Bravo Secret Plan')->assertDontSee('/ 9')->assertDontSee('Pay now');

        $manager = User::factory()->forBusiness($ownerA->business)->create(['role' => UserRole::Manager, 'quick_pin_setup_completed' => true]);
        $this->actingAs($manager)->get(route('subscription.show'))->assertForbidden();
    }

    /* ---------------------------------------------------------------- provisioning */

    public function test_provisioning_rolls_back_entirely_when_the_subscription_cannot_be_created(): void
    {
        $counts = fn (): array => collect(['businesses', 'business_settings', 'users', 'whatsapp_automations', 'business_subscriptions'])
            ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
        $before = $counts();

        config(['plans.default' => 'nonexistent']);
        try {
            $this->provision('No Plan Ltd', 'noplan@test.test', '08035550217');
            $this->fail('An undefined default plan must stop provisioning.');
        } catch (RuntimeException) {
            $this->assertSame($before, $counts());
        }

        config(['plans.default' => 'standard']);
        $this->app->instance(SubscriptionLifecycle::class, new class(app(AuditLogger::class), app(CurrentBusiness::class)) extends SubscriptionLifecycle
        {
            public function startTrial(Business $business, Plan $plan, User $owner): BusinessSubscription
            {
                throw new RuntimeException('subscription unavailable');
            }
        });

        try {
            $this->provision('Late Ltd', 'late@test.test', '08035550218');
            $this->fail('A failed subscription must stop provisioning.');
        } catch (RuntimeException) {
            $this->assertSame($before, $counts(), 'no orphan Business, owner, settings or automations');
        }
    }

    /* -------------------------------------------------------------------- helpers */

    private function provision(string $name, string $email, string $phone): User
    {
        app(CurrentBusiness::class)->forget();

        try {
            return app(ProvisionBusiness::class)->execute([
                'business_name' => $name, 'owner_name' => 'Owner of '.$name, 'email' => $email,
                'phone' => User::normalizePhone($phone), 'password' => 'Secret123',
            ])->fresh();
        } finally {
            app(CurrentBusiness::class)->set(Business::query()->orderBy('id')->firstOrFail());
        }
    }

    private function ready(User $owner): User
    {
        $owner->forceFill(['quick_pin_setup_completed' => true, 'email_verified_at' => now()])->save();

        return $owner->fresh();
    }

    /** Moves $business onto a fixture plan with explicit values, through the lifecycle. */
    private function onPlan(Business $business, string $key, array $entitlements): void
    {
        $plan = Plan::query()->where('key', $key)->first() ?? tap(new Plan, fn (Plan $plan) => $plan->forceFill([
            'key' => $key, 'name' => ucwords(str_replace('_', ' ', $key)), 'is_active' => true, 'price_minor' => null,
            'currency' => 'NGN', 'billing_interval' => 'month', 'trial_days' => 0, 'entitlements' => $entitlements,
        ])->save());

        app(SubscriptionLifecycle::class)->changePlan($business, $plan->fresh());
    }

    private function suspend(Business $business): void
    {
        DB::table('business_subscriptions')->where('business_id', $business->id)
            ->update(['status' => 'suspended', 'suspended_at' => now(), 'updated_at' => now()]);
    }

    private function addStaff(User $admin, string $email, string $phone)
    {
        return $this->actingAs($admin)->post(route('staff.store'), ['name' => 'Staff '.$email, 'email' => $email, 'phone' => $phone, 'role' => UserRole::SalesRep->value]);
    }

    private function inBusiness(Business $business, \Closure $work): mixed
    {
        return app(CurrentBusiness::class)->run($business, $work);
    }
}
