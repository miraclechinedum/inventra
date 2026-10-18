<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_the_pin_screen_wires_its_keypad_through_the_csp_safe_component(): void
    {
        $user = User::factory()->create(['quick_pin_setup_completed' => false]);

        $html = $this->actingAs($user)->get(route('onboarding.pin.edit'))->assertOk()->getContent();

        $this->assertStringContainsString('x-data="quickPinSetup(4)"', $html);
        $this->assertStringContainsString('x-on:keydown.window="keydown"', $html);
        foreach (range(0, 9) as $digit) {
            $this->assertStringContainsString('x-on:click="press('.$digit.')"', $html);
        }
        $this->assertStringContainsString('x-on:click="remove"', $html);
        $this->assertStringContainsString('name="pin_confirmation" x-bind:value="confirmation"', $html);

        // The CSP-safe Alpine build rejects statements and operators in attributes; any such
        // expression on this screen silently disables the keypad.
        preg_match_all('/x-(?:on:[\w.]+|bind:\w+|show|text)="([^"]*)"/', $html, $expressions);
        foreach ($expressions[1] as $expression) {
            $this->assertDoesNotMatchRegularExpression('/\bif\s*\(|\+=|=>|\|\||&&|\?|(?<![=!<>])=(?!=)/', $expression, "Inline logic in: {$expression}");
        }
    }

    public function test_mismatched_pins_are_rejected_server_side_and_nothing_is_stored(): void
    {
        $user = User::factory()->create(['quick_pin_setup_completed' => false]);

        $this->actingAs($user)->post(route('onboarding.pin.store'), ['pin' => '1234', 'pin_confirmation' => '4321'])
            ->assertSessionHasErrors(['pin' => 'The PINs did not match. Enter your new PIN again.']);
        $this->actingAs($user)->post(route('onboarding.pin.store'), ['pin' => '1234'])->assertSessionHasErrors('pin');

        $user->refresh();
        $this->assertNull($user->quick_pin_hash);
        $this->assertFalse($user->quick_pin_setup_completed);
        $this->assertDatabaseMissing('security_events', ['subject_user_id' => $user->id, 'event' => 'pin_set']);
    }

    public function test_a_repeated_submission_cannot_replace_a_pin_or_a_skipped_setup(): void
    {
        $user = User::factory()->create(['quick_pin_setup_completed' => false]);
        $this->actingAs($user);

        $this->post(route('onboarding.pin.store'), ['pin' => '1234', 'pin_confirmation' => '1234'])->assertRedirect(route('dashboard'));
        $hash = $user->fresh()->quick_pin_hash;

        $this->post(route('onboarding.pin.store'), ['pin' => '9999', 'pin_confirmation' => '9999'])->assertRedirect(route('dashboard'));
        $this->assertSame($hash, $user->fresh()->quick_pin_hash);
        $this->assertTrue(Hash::check('1234', $user->fresh()->quick_pin_hash));
        $this->assertSame(1, DB::table('security_events')->where('subject_user_id', $user->id)->where('event', 'pin_set')->count());
        $this->get(route('onboarding.pin.edit'))->assertRedirect(route('dashboard'));

        $skipper = User::factory()->create(['quick_pin_setup_completed' => false]);
        $this->actingAs($skipper)->post(route('onboarding.pin.skip'))->assertRedirect(route('dashboard'));
        $this->post(route('onboarding.pin.store'), ['pin' => '1234', 'pin_confirmation' => '1234'])->assertRedirect(route('dashboard'));
        $this->assertNull($skipper->fresh()->quick_pin_hash);
    }

    public function test_pin_setup_applies_only_to_the_signed_in_account(): void
    {
        $this->post(route('onboarding.pin.store'), ['pin' => '1234', 'pin_confirmation' => '1234'])->assertRedirect(route('login'));
        $this->get(route('onboarding.pin.edit'))->assertRedirect(route('login'));

        $user = User::factory()->create(['quick_pin_setup_completed' => false]);
        $other = User::factory()->create(['quick_pin_setup_completed' => false]);

        $this->actingAs($user)->post(route('onboarding.pin.store'), [
            'pin' => '1234', 'pin_confirmation' => '1234', 'user_id' => $other->id, 'id' => $other->id, 'email' => $other->email,
        ])->assertRedirect(route('dashboard'));

        $this->assertTrue(Hash::check('1234', $user->fresh()->quick_pin_hash));
        $this->assertNull($other->fresh()->quick_pin_hash);
        $this->assertFalse($other->fresh()->quick_pin_setup_completed);
    }

    public function test_an_unverified_signup_owner_cannot_reach_pin_setup(): void
    {
        $owner = User::factory()->create(['quick_pin_setup_completed' => false, 'email_verification_required' => true, 'email_verified_at' => null]);

        $this->actingAs($owner)->get(route('onboarding.pin.edit'))->assertRedirect(route('verification.notice'));
        $this->post(route('onboarding.pin.store'), ['pin' => '1234', 'pin_confirmation' => '1234'])->assertRedirect(route('verification.notice'));
        $this->assertNull($owner->fresh()->quick_pin_hash);
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
