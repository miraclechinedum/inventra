<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_forced_change_user_cannot_bypass_password_page(): void
    {
        $user = User::factory()->create(['force_password_change' => true]);

        $this->actingAs($user)->get('/dashboard')
            ->assertRedirect(route('onboarding.password.edit'));
        $this->get('/onboarding/password')->assertOk()->assertSee('Create your password');
    }

    public function test_valid_password_change_hashes_password_clears_flag_and_regenerates_session(): void
    {
        $user = User::factory()->create(['force_password_change' => true, 'quick_pin_setup_completed' => false]);
        $this->actingAs($user)->get('/onboarding/password');
        $oldSessionId = session()->getId();

        $this->post('/onboarding/password', [
            'password' => 'SecurePass9',
            'password_confirmation' => 'SecurePass9',
        ])->assertRedirect(route('onboarding.pin.edit'));

        $user->refresh();
        $this->assertFalse($user->force_password_change);
        $this->assertTrue(Hash::check('SecurePass9', $user->password));
        $this->assertNotSame($oldSessionId, session()->getId());
        $this->assertDatabaseHas('security_events', ['user_id' => $user->id, 'event' => 'password_changed']);
    }

    public function test_weak_password_is_rejected(): void
    {
        $user = User::factory()->create(['force_password_change' => true]);

        $this->actingAs($user)->post('/onboarding/password', [
            'password' => 'weakpass',
            'password_confirmation' => 'weakpass',
        ])->assertSessionHasErrors('password');

        $this->assertTrue($user->fresh()->force_password_change);
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_valid_pin_is_hashed_and_never_exposed(): void
    {
        $user = User::factory()->create(['quick_pin_setup_completed' => false]);

        $response = $this->actingAs($user)->post('/onboarding/pin', [
            'pin' => '1234',
            'pin_confirmation' => '1234',
        ]);

        $response->assertRedirect(route('dashboard'))->assertDontSee('1234');
        $user->refresh();
        $this->assertTrue($user->quick_pin_setup_completed);
        $this->assertTrue(Hash::check('1234', $user->quick_pin_hash));
        $this->assertArrayNotHasKey('quick_pin_hash', $user->toArray());
    }

    public function test_invalid_pin_is_rejected_and_pin_can_be_skipped(): void
    {
        $user = User::factory()->create(['quick_pin_setup_completed' => false]);

        $this->actingAs($user)->post('/onboarding/pin', [
            'pin' => '12345',
            'pin_confirmation' => '12345',
        ])->assertSessionHasErrors('pin');

        $this->assertNull($user->fresh()->quick_pin_hash);

        $this->post('/onboarding/pin/skip')->assertRedirect(route('dashboard'));
        $this->assertTrue($user->fresh()->quick_pin_setup_completed);
        $this->assertNull($user->fresh()->quick_pin_hash);
    }

    public function test_pin_setup_is_rate_limited(): void
    {
        $user = User::factory()->create(['quick_pin_setup_completed' => false]);
        $this->actingAs($user);

        foreach (range(1, config('auth_security.pin.max_attempts')) as $attempt) {
            $this->post('/onboarding/pin', ['pin' => 'invalid'])->assertSessionHasErrors('pin');
        }

        $this->post('/onboarding/pin', ['pin' => 'invalid'])->assertTooManyRequests();
    }

    public function test_onboarding_payload_cannot_tamper_with_privileged_fields(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::SalesRep,
            'status' => UserStatus::Active,
            'force_password_change' => true,
            'quick_pin_setup_completed' => false,
        ]);

        $this->actingAs($user)->post('/onboarding/password', [
            'password' => 'SecurePass9',
            'password_confirmation' => 'SecurePass9',
            'role' => UserRole::Admin->value,
            'status' => UserStatus::Locked->value,
            'failed_login_attempts' => 99,
        ]);

        $user->refresh();
        $this->assertSame(UserRole::SalesRep, $user->role);
        $this->assertSame(UserStatus::Active, $user->status);
        $this->assertSame(0, $user->failed_login_attempts);

        $this->post('/onboarding/pin', [
            'pin' => '1234',
            'pin_confirmation' => '1234',
            'role' => UserRole::Admin->value,
            'status' => UserStatus::Locked->value,
        ]);

        $this->assertSame(UserRole::SalesRep, $user->fresh()->role);
        $this->assertSame(UserStatus::Active, $user->fresh()->status);
    }
}
