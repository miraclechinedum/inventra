<?php

namespace Tests\Feature\Staff;

use App\Actions\Staff\ActivateStaff;
use App\Actions\Staff\RevokeStaffSessions;
use App\Actions\Staff\UnlockStaff;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Services\SecurityEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use LogicException;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class StaffManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($this->admin);
    }

    public function test_admin_creates_manager_with_one_time_hashed_credentials_and_canonical_profile(): void
    {
        $response = $this->post('/staff', $this->payload());
        $user = User::query()->where('email', 'manager@example.com')->firstOrFail();
        $temporaryPassword = $response->viewData('temporaryPassword');

        $response->assertOk()->assertViewIs('staff.created');
        $this->assertIsString($temporaryPassword);
        $this->assertTrue(Hash::check($temporaryPassword, $user->password));
        $this->assertSame(UserRole::Manager, $user->role);
        $this->assertSame(UserStatus::Active, $user->status);
        $this->assertTrue($user->force_password_change);
        $this->assertFalse($user->quick_pin_setup_completed);
        $this->assertSame('+2348012345678', $user->phone);
        $this->assertSame($this->admin->id, $user->created_by);
        $this->assertStringNotContainsString($temporaryPassword, json_encode($user->getAttributes()));

        $event = SecurityEvent::query()->where('event', 'staff_created')->firstOrFail();
        $this->assertSame($this->admin->id, $event->actor_id);
        $this->assertSame($user->id, $event->subject_user_id);
        $this->assertStringNotContainsString($temporaryPassword, $event->toJson());
        $this->assertStringNotContainsString($temporaryPassword, $response->headers->get('Location', ''));

        DB::table('sessions')->pluck('payload')->each(function (string $payload) use ($temporaryPassword): void {
            $this->assertStringNotContainsString($temporaryPassword, $payload);
            $this->assertStringNotContainsString($temporaryPassword, (string) base64_decode($payload, true));
        });

        $this->get(route('staff.show', $user))->assertOk()->assertDontSee($temporaryPassword);
    }

    public function test_admin_creates_sales_rep_but_cannot_create_admin_or_tamper_with_state(): void
    {
        $payload = $this->payload([
            'email' => 'sales@example.com',
            'phone' => '08022223333',
            'role' => UserRole::SalesRep->value,
            'status' => UserStatus::Locked->value,
            'created_by' => User::factory()->create()->id,
            'force_password_change' => false,
            'quick_pin_hash' => '1234',
            'failed_login_attempts' => 99,
        ]);

        $this->post('/staff', $payload)->assertOk();
        $user = User::query()->where('email', 'sales@example.com')->firstOrFail();
        $this->assertSame(UserRole::SalesRep, $user->role);
        $this->assertSame(UserStatus::Active, $user->status);
        $this->assertSame($this->admin->id, $user->created_by);
        $this->assertTrue($user->force_password_change);
        $this->assertNull($user->quick_pin_hash);
        $this->assertSame(0, $user->failed_login_attempts);

        $this->post('/staff', $this->payload(['email' => 'admin2@example.com', 'role' => 'admin']))
            ->assertSessionHasErrors('role');
        $this->assertDatabaseMissing('users', ['email' => 'admin2@example.com']);
    }

    public function test_duplicate_and_invalid_identifiers_are_rejected_cleanly(): void
    {
        User::factory()->create(['email' => 'manager@example.com', 'phone' => '+2348012345678']);

        $this->post('/staff', $this->payload())->assertSessionHasErrors('email');
        $this->post('/staff', $this->payload(['email' => 'other@example.com', 'phone' => '234 801 234 5678']))
            ->assertSessionHasErrors('phone');
        $this->post('/staff', $this->payload(['email' => 'invalid@example.com', 'phone' => 'not-phone']))
            ->assertSessionHasErrors('phone');
    }

    public function test_admin_updates_profile_and_changes_roles_in_both_directions(): void
    {
        $user = User::factory()->create(['role' => UserRole::Manager]);

        $this->put(route('staff.update', $user), [
            'name' => 'Updated Person',
            'email' => 'UPDATED@example.com',
            'phone' => '0803 111 2222',
            'status' => UserStatus::Locked->value,
            'force_password_change' => true,
        ])->assertRedirect(route('staff.show', $user));
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Updated Person',
            'email' => 'updated@example.com',
            'phone' => '+2348031112222',
            'status' => UserStatus::Active->value,
        ]);

        $this->post(route('staff.role', $user), ['role' => UserRole::SalesRep->value])->assertRedirect();
        $this->assertSame(UserRole::SalesRep, $user->fresh()->role);
        $this->post(route('staff.role', $user), ['role' => UserRole::Manager->value])->assertRedirect();
        $this->assertSame(UserRole::Manager, $user->fresh()->role);
        $this->post(route('staff.role', $user), ['role' => 'admin'])->assertSessionHasErrors('role');
        $this->assertSame(UserRole::Manager, $user->fresh()->role);
    }

    public function test_status_lock_unlock_and_password_change_lifecycle_is_explicit(): void
    {
        $user = User::factory()->create([
            'failed_login_attempts' => 4,
            'locked_until' => now()->addMinute(),
            'last_failed_login_at' => now(),
        ]);

        $this->post(route('staff.deactivate', $user))->assertRedirect();
        $this->assertSame(UserStatus::Inactive, $user->fresh()->status);
        $this->post(route('staff.activate', $user))->assertRedirect();
        $this->assertSame(UserStatus::Active, $user->fresh()->status);
        $this->post(route('staff.lock', $user))->assertRedirect();
        $this->assertSame(UserStatus::Locked, $user->fresh()->status);
        $this->assertNotNull($user->fresh()->locked_until);
        $this->post(route('staff.unlock', $user))->assertRedirect();

        $user->refresh();
        $this->assertSame(UserStatus::Active, $user->status);
        $this->assertSame(0, $user->failed_login_attempts);
        $this->assertNull($user->locked_until);
        $this->assertNull($user->last_failed_login_at);

        $this->post(route('staff.require-password-change', $user))->assertRedirect();
        $this->assertTrue($user->fresh()->force_password_change);
    }

    public function test_session_revocation_targets_only_the_selected_staff_member(): void
    {
        config()->set('session.driver', 'database');
        $target = User::factory()->create();
        $unrelated = User::factory()->create();
        $this->insertSession('target-session', $target);
        $this->insertSession('unrelated-session', $unrelated);

        $this->post(route('staff.revoke-sessions', $target))->assertRedirect();

        $this->assertDatabaseMissing('sessions', ['id' => 'target-session']);
        $this->assertDatabaseHas('sessions', ['id' => 'unrelated-session']);
        $this->assertDatabaseHas('security_events', [
            'event' => 'staff_sessions_revoked',
            'actor_id' => $this->admin->id,
            'subject_user_id' => $target->id,
        ]);
    }

    public function test_role_change_and_deactivation_revoke_sessions_and_block_access(): void
    {
        config()->set('session.driver', 'database');
        $target = User::factory()->create(['role' => UserRole::Manager]);
        $this->insertSession('role-session', $target);
        $this->post(route('staff.role', $target), ['role' => UserRole::SalesRep->value]);
        $this->assertDatabaseMissing('sessions', ['id' => 'role-session']);

        $this->insertSession('status-session', $target);
        $this->post(route('staff.deactivate', $target));
        $this->assertDatabaseMissing('sessions', ['id' => 'status-session']);

        config()->set('session.driver', 'array');
        $this->actingAs($target)->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_staff_search_filters_and_pagination_are_applied(): void
    {
        User::factory()->create(['name' => 'Ada Manager', 'email' => 'ada@example.com', 'phone' => '08035556666', 'role' => UserRole::Manager]);
        User::factory()->create(['name' => 'Bola Sales', 'email' => 'bola@example.com', 'phone' => '08047778888', 'role' => UserRole::SalesRep, 'status' => UserStatus::Inactive]);

        $this->get('/staff?search=Ada')->assertOk()->assertSee('Ada Manager')->assertDontSee('Bola Sales');
        $this->get('/staff?search=bola@example.com')->assertOk()->assertSee('Bola Sales')->assertDontSee('Ada Manager');
        $this->get('/staff?search=234%20803%20555%206666')->assertOk()->assertSee('Ada Manager');
        $this->get('/staff?role=manager')->assertOk()->assertSee('Ada Manager')->assertDontSee('Bola Sales');
        $this->get('/staff?status=inactive')->assertOk()->assertSee('Bola Sales')->assertDontSee('Ada Manager');

        User::factory()->count(16)->create();
        $this->get('/staff')->assertOk()->assertSee('Next');
    }

    public function test_staff_mutations_require_a_valid_csrf_token(): void
    {
        $route = app('router')->getRoutes()->getByName('staff.store');

        $this->assertContains('web', $route->gatherMiddleware());
        $this->get('/staff/create')->assertOk()->assertSee('name="_token"', false);
    }

    public function test_activity_page_shows_safe_actor_attribution(): void
    {
        $target = User::factory()->create();
        $this->post(route('staff.lock', $target));

        $this->get(route('staff.activity', $target))
            ->assertOk()
            ->assertSee('Staff Locked')
            ->assertSee($this->admin->name)
            ->assertDontSee('quick_pin_hash')
            ->assertDontSee('remember_token');
    }

    public function test_array_shaped_filters_are_ignored_and_array_shaped_writes_fail_validation(): void
    {
        $this->get('/staff?search[]=x&role[]=admin&status[]=locked')->assertOk();

        $this->post('/staff', [
            'name' => ['Nested'],
            'email' => ['nested@example.com'],
            'phone' => ['08012345678'],
            'role' => ['manager'],
        ])->assertSessionHasErrors(['name', 'email', 'phone', 'role']);

        $subject = User::factory()->create();
        $this->put(route('staff.update', $subject), [
            'name' => ['Nested'],
            'email' => ['nested@example.com'],
            'phone' => ['08012345678'],
        ])->assertSessionHasErrors(['name', 'email', 'phone']);
    }

    public function test_unlock_is_allowed_only_from_manually_locked_state(): void
    {
        $locked = User::factory()->create([
            'status' => UserStatus::Locked,
            'failed_login_attempts' => 5,
            'locked_until' => now()->addMinute(),
            'last_failed_login_at' => now(),
        ]);
        $inactive = User::factory()->create(['status' => UserStatus::Inactive]);
        $active = User::factory()->create(['status' => UserStatus::Active]);

        $this->post(route('staff.unlock', $locked))->assertRedirect();
        $this->assertSame(UserStatus::Active, $locked->fresh()->status);
        $this->assertSame(0, $locked->fresh()->failed_login_attempts);
        $this->assertNull($locked->fresh()->locked_until);
        $this->post(route('staff.unlock', $inactive))->assertForbidden();
        $this->post(route('staff.unlock', $active))->assertForbidden();
        $this->assertSame(UserStatus::Inactive, $inactive->fresh()->status);
        $this->assertSame(UserStatus::Active, $active->fresh()->status);
    }

    public function test_session_revocation_fails_closed_for_unsupported_drivers_without_audit_event(): void
    {
        config()->set('session.driver', 'file');
        $target = User::factory()->create();
        $action = app(RevokeStaffSessions::class);

        try {
            $action->execute($this->admin, $target);
            $this->fail('Unsupported session drivers must fail closed.');
        } catch (LogicException) {
            $this->assertDatabaseMissing('security_events', [
                'event' => 'staff_sessions_revoked',
                'subject_user_id' => $target->id,
            ]);
        }
    }

    public function test_activate_and_unlock_roll_back_when_audit_recording_fails(): void
    {
        $events = Mockery::mock(SecurityEventRecorder::class);
        $events->shouldReceive('record')->twice()->andThrow(new RuntimeException('audit unavailable'));
        $inactive = User::factory()->create(['status' => UserStatus::Inactive]);
        $locked = User::factory()->create(['status' => UserStatus::Locked, 'failed_login_attempts' => 5]);

        foreach ([
            [new ActivateStaff($events), $inactive],
            [new UnlockStaff($events), $locked],
        ] as [$action, $subject]) {
            try {
                $action->execute($this->admin, $subject);
                $this->fail('The action should fail with its audit write.');
            } catch (RuntimeException) {
                $subject->refresh();
            }
        }

        $this->assertSame(UserStatus::Inactive, $inactive->status);
        $this->assertSame(UserStatus::Locked, $locked->status);
        $this->assertSame(5, $locked->failed_login_attempts);
    }

    public function test_search_treats_like_wildcards_as_literal_characters(): void
    {
        User::factory()->create(['name' => '% Percent Person']);
        User::factory()->create(['name' => '_ Underscore Person']);
        User::factory()->create(['name' => '\\ Backslash Person']);
        User::factory()->create(['name' => 'Ordinary Person']);

        $this->get('/staff?search=%25')->assertOk()->assertSee('% Percent Person')->assertDontSee('Ordinary Person');
        $this->get('/staff?search=_')->assertOk()->assertSee('_ Underscore Person')->assertDontSee('Ordinary Person');
        $this->get('/staff?search=%5C')->assertOk()->assertSee('\\ Backslash Person')->assertDontSee('Ordinary Person');
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'New Manager',
            'email' => 'MANAGER@example.com',
            'phone' => '0801 234 5678',
            'role' => UserRole::Manager->value,
        ], $overrides);
    }

    private function insertSession(string $id, User $user): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);
    }
}
