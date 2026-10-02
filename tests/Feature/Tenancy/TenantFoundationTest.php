<?php

namespace Tests\Feature\Tenancy;

use App\Actions\Settings\UpdateBusinessSettings;
use App\Actions\Staff\CreateStaff;
use App\Enums\BusinessStatus;
use App\Enums\UserRole;
use App\Http\Middleware\ResolveCurrentBusiness;
use App\Models\Business;
use App\Models\BusinessSetting;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Settings\BusinessSettings;
use App\Tenancy\BusinessContextException;
use App\Tenancy\CurrentBusiness;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class TenantFoundationTest extends TestCase
{
    use RefreshDatabase;

    private Business $businessA;

    private Business $businessB;

    private User $adminA;

    private User $adminB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->businessA = Business::factory()->create(['name' => 'Alpha Spares']);
        $this->businessB = Business::factory()->create(['name' => 'Bravo Hardware']);
        $this->adminA = User::factory()->forBusiness($this->businessA)->create(['role' => UserRole::Admin]);
        $this->adminB = User::factory()->forBusiness($this->businessB)->create(['role' => UserRole::Admin]);

        // A probe behind the real tenant middleware stack, so the resolved context is observable.
        Route::middleware(['web', 'auth', 'active', 'business'])
            ->get('/_tenancy-probe', fn (CurrentBusiness $current) => response()->json(['business_id' => $current->id()]));
    }

    /* ---------------------------------------------------------------- relationships */

    public function test_a_business_owns_its_users_and_exactly_one_settings_record(): void
    {
        $manager = User::factory()->forBusiness($this->businessA)->create(['role' => UserRole::Manager]);

        $this->assertTrue($this->adminA->business->is($this->businessA));
        $this->assertTrue($manager->business->is($this->businessA));
        $this->assertEqualsCanonicalizing([$this->adminA->id, $manager->id], $this->businessA->users()->pluck('id')->all());
        $this->assertSame([$this->adminB->id], $this->businessB->users()->pluck('id')->all());

        $settings = $this->businessA->settings;
        $this->assertInstanceOf(BusinessSetting::class, $settings);
        $this->assertTrue($settings->business->is($this->businessA));
        $this->assertTrue($settings->belongsToBusiness($this->businessA));
        $this->assertFalse($settings->belongsToBusiness($this->businessB));
        $this->assertSame(1, BusinessSetting::query()->forBusiness($this->businessA)->count());
    }

    public function test_a_plain_factory_user_belongs_to_the_bootstrapped_installation_business(): void
    {
        $installation = Business::query()->orderBy('id')->firstOrFail();

        $this->assertSame($installation->id, User::factory()->create()->business_id);
    }

    /* --------------------------------------------------------------- CurrentBusiness */

    public function test_the_request_context_is_the_signed_in_users_business_and_does_not_outlive_it(): void
    {
        $current = app(CurrentBusiness::class);
        $current->forget();

        $this->actingAs($this->adminA)->getJson('/_tenancy-probe')->assertOk()->assertJson(['business_id' => $this->businessA->id]);
        $this->assertFalse($current->has(), 'The context must end with the request');

        $this->actingAs($this->adminB)->getJson('/_tenancy-probe')->assertOk()->assertJson(['business_id' => $this->businessB->id]);
        $this->assertFalse($current->has());
    }

    public function test_a_request_cannot_choose_its_business(): void
    {
        $this->actingAs($this->adminA)
            ->getJson('/_tenancy-probe?business_id='.$this->businessB->id, ['X-Business-Id' => (string) $this->businessB->id])
            ->assertOk()
            ->assertJson(['business_id' => $this->businessA->id]);
    }

    public function test_reading_the_context_without_one_fails_closed(): void
    {
        app(CurrentBusiness::class)->forget();
        $this->expectException(BusinessContextException::class);

        app(CurrentBusiness::class)->get();
    }

    public function test_a_context_that_disagrees_with_the_signed_in_user_is_refused(): void
    {
        $this->actingAs($this->adminA);
        app(CurrentBusiness::class)->set($this->businessB);

        $this->expectException(BusinessContextException::class);

        app(CurrentBusiness::class)->get();
    }

    public function test_run_scopes_an_explicit_business_and_restores_the_previous_context(): void
    {
        $current = app(CurrentBusiness::class);
        $current->set($this->businessA);

        $seen = $current->run($this->businessB, fn (Business $business) => $current->id());
        $this->assertSame($this->businessB->id, $seen);
        $this->assertSame($this->businessA->id, $current->id());

        try {
            $current->run($this->businessB, fn () => throw new RuntimeException('work failed'));
        } catch (RuntimeException) {
            // expected
        }
        $this->assertSame($this->businessA->id, $current->id(), 'A failing job must not leave its business behind');
    }

    public function test_acting_for_a_business_requires_an_active_business_the_actor_belongs_to(): void
    {
        $current = app(CurrentBusiness::class);
        $current->forget();
        $this->assertTrue($current->forActor($this->adminA)->is($this->businessA));

        // Unsaved: the database no longer admits a user without a business at all.
        $tenantless = User::factory()->make(['business_id' => null]);
        $suspended = User::factory()->forBusiness(Business::factory()->suspended()->create())->create();

        foreach ([$tenantless, $suspended] as $actor) {
            try {
                $current->forActor($actor);
                $this->fail('An actor without an active business must be refused.');
            } catch (BusinessContextException) {
                // expected
            }
        }

        $current->set($this->businessB);
        $this->expectException(BusinessContextException::class);
        $current->forActor($this->adminA);
    }

    /* -------------------------------------------------------------------- middleware */

    public function test_a_user_without_a_business_cannot_be_stored(): void
    {
        $this->expectException(QueryException::class);

        User::factory()->create(['business_id' => null]);
    }

    public function test_the_middleware_refuses_a_user_without_a_business(): void
    {
        // Unreachable through the database now, so the fail-closed branch is exercised directly.
        $request = Request::create('/dashboard');
        $request->setLaravelSession(app('session')->driver());
        $request->setUserResolver(fn () => User::factory()->make(['business_id' => null]));

        $response = app(ResolveCurrentBusiness::class)->handle($request, fn () => response('reached'));

        $this->assertTrue($response->isRedirect(route('login')));
        $this->assertSame(['reason' => 'no_business'], SecurityEvent::query()
            ->where('event', 'business_access_denied')->latest('id')->firstOrFail()->metadata);
    }

    public function test_a_suspended_business_is_refused_for_pages_onboarding_and_json(): void
    {
        $this->businessA->forceFill(['status' => BusinessStatus::Suspended])->save();

        $this->actingAs($this->adminA)->get(route('settings.business.edit'))->assertRedirect(route('login'));
        $this->assertGuest();

        $this->actingAs($this->adminA)->get(route('onboarding.password.edit'))->assertRedirect(route('login'));
        $this->actingAs($this->adminA)->getJson('/_tenancy-probe')->assertForbidden();

        $this->assertSame('business_suspended', SecurityEvent::query()
            ->where('event', 'business_access_denied')->where('subject_user_id', $this->adminA->id)->firstOrFail()->metadata['reason']);
    }

    public function test_public_routes_logout_and_the_webhook_do_not_depend_on_a_business(): void
    {
        $this->get(route('home'))->assertOk();
        $this->get(route('login'))->assertOk();
        $this->get(route('password.request'))->assertOk();

        config(['whatsapp.verify_token' => 'TENANCY-VERIFY-TOKEN']);
        $this->get(route('webhooks.whatsapp.verify', [
            'hub_mode' => 'subscribe', 'hub_verify_token' => 'TENANCY-VERIFY-TOKEN', 'hub_challenge' => 'challenge-123',
        ]))->assertOk()->assertSee('challenge-123');

        // A user whose business is suspended can still end the session.
        $this->businessA->forceFill(['status' => BusinessStatus::Suspended])->save();
        $this->actingAs($this->adminA)->post(route('logout'))->assertRedirect();
        $this->assertGuest();
    }

    /* ------------------------------------------------------------ settings isolation */

    public function test_an_administrator_sees_only_their_own_business_settings(): void
    {
        $this->actingAs($this->adminA)->get(route('settings.business.edit'))
            ->assertOk()->assertSee('Alpha Spares')->assertDontSee('Bravo Hardware');

        $this->actingAs($this->adminB)->get(route('settings.business.edit'))
            ->assertOk()->assertSee('Bravo Hardware')->assertDontSee('Alpha Spares');

        $this->assertTrue($this->adminA->can('update', $this->businessA->settings));
        $this->assertFalse($this->adminA->can('view', $this->businessB->settings));
        $this->assertFalse($this->adminA->can('update', $this->businessB->settings));
    }

    public function test_an_administrator_updates_only_their_own_business_settings(): void
    {
        $before = DB::table('business_settings')->where('business_id', $this->businessB->id)->first();

        $this->actingAs($this->adminA)->put(route('settings.business.update'), $this->settingsPayload(['business_name' => 'Alpha Renamed']))
            ->assertRedirect(route('settings.business.edit'));

        $this->assertSame('Alpha Renamed', $this->businessA->settings()->value('business_name'));
        $this->assertEquals($before, DB::table('business_settings')->where('business_id', $this->businessB->id)->first());
    }

    public function test_a_settings_payload_cannot_redirect_the_write_to_another_business(): void
    {
        $snapshot = DB::table('business_settings')->orderBy('id')->get()->toArray();

        foreach (['business_id' => $this->businessB->id, 'id' => $this->businessB->settings->id] as $field => $value) {
            $this->actingAs($this->adminA)
                ->put(route('settings.business.update'), $this->settingsPayload(['business_name' => 'Hijack', $field => $value]))
                ->assertSessionHasErrors($field);
        }

        $this->assertEquals($snapshot, DB::table('business_settings')->orderBy('id')->get()->toArray());
    }

    public function test_the_settings_action_refuses_a_context_belonging_to_another_business(): void
    {
        app(CurrentBusiness::class)->set($this->businessB);

        try {
            app(UpdateBusinessSettings::class)->execute($this->adminA, ['business_name' => 'Cross Tenant']);
            $this->fail('A mismatched business context must be refused.');
        } catch (BusinessContextException) {
            // expected
        }

        $this->assertNotSame('Cross Tenant', $this->businessA->settings()->value('business_name'));
        $this->assertNotSame('Cross Tenant', $this->businessB->settings()->value('business_name'));
    }

    public function test_settings_reads_are_keyed_by_business(): void
    {
        $settings = app(BusinessSettings::class);

        $this->assertSame('Alpha Spares', $settings->for($this->businessA)->business_name);
        $this->assertSame('Bravo Hardware', $settings->for($this->businessB)->business_name);

        app(CurrentBusiness::class)->set($this->businessB);
        $this->assertSame('Bravo Hardware', $settings->current()->business_name);
    }

    public function test_no_single_business_settings_resolver_remains(): void
    {
        // WhatsApp resolves its Business from the record it reacts to, so nothing may answer
        // "the" business outside a tenant request.
        $this->assertFalse(method_exists(BusinessSettings::class, 'forSingleBusinessAutomation'));
    }

    /* ------------------------------------------------------------------ staff ownership */

    public function test_staff_join_the_creating_administrators_business(): void
    {
        $this->actingAs($this->adminA)->post(route('staff.store'), [
            'name' => 'Chidi Okeke', 'email' => 'chidi@example.com', 'phone' => '08031112222', 'role' => UserRole::Manager->value,
        ])->assertOk();

        $this->assertSame($this->businessA->id, User::query()->where('email', 'chidi@example.com')->sole()->business_id);
    }

    public function test_a_staff_form_cannot_place_a_user_in_another_business(): void
    {
        $this->actingAs($this->adminA)->post(route('staff.store'), [
            'name' => 'Intruder', 'email' => 'intruder@example.com', 'phone' => '08031113333',
            'role' => UserRole::Manager->value, 'business_id' => $this->businessB->id,
        ])->assertSessionHasErrors('business_id');
        $this->assertFalse(User::query()->where('email', 'intruder@example.com')->exists());

        $member = User::factory()->forBusiness($this->businessA)->create(['role' => UserRole::SalesRep]);
        $this->actingAs($this->adminA)->put(route('staff.update', $member), [
            'name' => $member->name, 'email' => $member->email, 'phone' => $member->phone, 'business_id' => $this->businessB->id,
        ])->assertSessionHasErrors('business_id');

        $this->actingAs($member)->put(route('profile.update'), [
            'name' => $member->name, 'email' => $member->email, 'phone' => $member->phone, 'business_id' => $this->businessB->id,
        ])->assertSessionHasErrors('business_id');

        $this->assertSame($this->businessA->id, $member->fresh()->business_id);
    }

    public function test_staff_creation_refuses_a_context_belonging_to_another_business(): void
    {
        app(CurrentBusiness::class)->set($this->businessB);

        try {
            app(CreateStaff::class)->execute($this->adminA, ['name' => 'Wrong Tenant', 'email' => 'wrong@example.com', 'phone' => null], UserRole::SalesRep);
            $this->fail('A mismatched business context must be refused.');
        } catch (BusinessContextException) {
            // expected
        }

        $this->assertFalse(User::query()->where('email', 'wrong@example.com')->exists());
    }

    /* ---------------------------------------------------------------------- roles */

    public function test_roles_keep_their_meaning_inside_a_business(): void
    {
        $manager = User::factory()->forBusiness($this->businessA)->create(['role' => UserRole::Manager]);
        $rep = User::factory()->forBusiness($this->businessA)->create(['role' => UserRole::SalesRep]);

        $this->actingAs($this->adminA)->get(route('settings.business.edit'))->assertOk();
        $this->actingAs($manager)->get(route('settings.business.edit'))->assertForbidden();
        $this->actingAs($rep)->get(route('settings.business.edit'))->assertForbidden();
        $this->actingAs($manager)->get(route('dashboard'))->assertOk();
        $this->actingAs($rep)->get(route('dashboard'))->assertOk();
    }

    /* --------------------------------------------------------------- create-admin */

    public function test_the_bootstrap_command_refuses_to_choose_between_businesses(): void
    {
        $this->artisan('inventra:create-admin')
            ->expectsOutput('More than one business exists. Name the business with --business=<id>.')
            ->assertFailed();

        $this->artisan('inventra:create-admin', ['--business' => '999999'])
            ->expectsOutput('No business has that id.')
            ->assertFailed();
    }

    private function settingsPayload(array $overrides = []): array
    {
        return array_merge([
            'business_name' => 'Alpha Spares', 'business_phone' => null,
            'business_address' => null, 'receipt_footer' => null, 'currency' => 'NGN',
        ], $overrides);
    }
}
