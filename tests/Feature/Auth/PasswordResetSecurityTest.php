<?php

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_notifications_are_sent_only_to_active_accounts(): void
    {
        Notification::fake();
        $active = User::factory()->create(['status' => UserStatus::Active]);
        $inactive = User::factory()->create(['status' => UserStatus::Inactive]);
        $locked = User::factory()->create(['status' => UserStatus::Locked]);
        $message = 'If an account exists for that email address, password reset instructions have been sent.';

        foreach ([$active, $inactive, $locked] as $user) {
            $this->post('/forgot-password', ['email' => strtoupper($user->email)])
                ->assertSessionHas('status', $message);
        }

        Notification::assertSentTo($active, ResetPassword::class);
        Notification::assertNotSentTo($inactive, ResetPassword::class);
        Notification::assertNotSentTo($locked, ResetPassword::class);
    }

    public function test_existing_token_cannot_reset_inactive_or_manually_locked_account(): void
    {
        foreach ([UserStatus::Inactive, UserStatus::Locked] as $status) {
            $user = User::factory()->create();
            $token = Password::createToken($user);
            $user->forceFill(['status' => $status])->save();

            $this->post('/reset-password', [
                'token' => $token,
                'email' => strtoupper($user->email),
                'password' => 'ResetPass9',
                'password_confirmation' => 'ResetPass9',
            ])->assertSessionHasErrors('email');

            $this->assertSame($status, $user->fresh()->status);
        }
    }

    public function test_reset_email_throttle_applies_across_different_ip_addresses(): void
    {
        Notification::fake();
        config()->set('auth_security.password_reset.max_attempts', 1);
        $user = User::factory()->create();

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.2'])
            ->post('/forgot-password', ['email' => strtoupper($user->email)])
            ->assertRedirect();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.3'])
            ->post('/forgot-password', ['email' => $user->email])
            ->assertTooManyRequests();
    }

    public function test_reset_link_uses_configured_host(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
            $url = $notification->toMail($user)->actionUrl;

            return parse_url($url, PHP_URL_HOST) === parse_url(config('app.url'), PHP_URL_HOST);
        });
    }
}
