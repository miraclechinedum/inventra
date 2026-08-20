<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_known_and_unknown_email_responses_are_indistinguishable_and_token_is_not_leaked(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $known = $this->post('/forgot-password', ['email' => $user->email]);
        $unknown = $this->post('/forgot-password', ['email' => 'unknown@example.com']);

        $message = 'If an account exists for that email address, password reset instructions have been sent.';
        $known->assertSessionHas('status', $message)->assertDontSee('token');
        $unknown->assertSessionHas('status', $message)->assertDontSee('token');
        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_password_reset_requests_are_rate_limited(): void
    {
        Notification::fake();

        foreach (range(1, config('auth_security.password_reset.max_attempts')) as $attempt) {
            $this->post('/forgot-password', ['email' => "staff{$attempt}@example.com"])->assertRedirect();
        }

        $this->post('/forgot-password', ['email' => 'limited@example.com'])->assertTooManyRequests();
    }

    public function test_valid_token_resets_password_and_security_state(): void
    {
        Notification::fake();
        $user = User::factory()->create([
            'failed_login_attempts' => 5,
            'locked_until' => now()->addMinutes(10),
            'force_password_change' => true,
        ]);
        $token = null;

        $this->post('/forgot-password', ['email' => $user->email]);
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token): bool {
            $token = $notification->token;

            return true;
        });

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'ResetPass9',
            'password_confirmation' => 'ResetPass9',
        ])->assertRedirect(route('login'));

        $user->refresh();
        $this->assertTrue(Hash::check('ResetPass9', $user->password));
        $this->assertSame(0, $user->failed_login_attempts);
        $this->assertNull($user->locked_until);
        $this->assertFalse($user->force_password_change);
        $this->assertDatabaseHas('security_events', ['user_id' => $user->id, 'event' => 'password_reset_completed']);
    }

    public function test_invalid_token_does_not_reset_password(): void
    {
        $user = User::factory()->create();

        $this->post('/reset-password', [
            'token' => 'invalid-token',
            'email' => $user->email,
            'password' => 'ResetPass9',
            'password_confirmation' => 'ResetPass9',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_expired_token_does_not_reset_password(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $token = null;

        $this->post('/forgot-password', ['email' => $user->email]);
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token): bool {
            $token = $notification->token;

            return true;
        });
        DB::table('password_reset_tokens')->where('email', $user->email)->update([
            'created_at' => now()->subMinutes(config('auth.passwords.users.expire') + 1),
        ]);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'ResetPass9',
            'password_confirmation' => 'ResetPass9',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }
}
