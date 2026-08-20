<?php

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_with_email_and_session_is_regenerated(): void
    {
        $user = User::factory()->create(['email' => 'STAFF@example.com']);
        $this->get('/login');
        $oldSessionId = session()->getId();

        $this->post('/login', ['identifier' => 'staff@example.com', 'password' => 'password'])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($oldSessionId, session()->getId());
        $this->assertDatabaseHas('security_events', ['user_id' => $user->id, 'event' => 'login_success']);
    }

    public function test_user_can_login_with_normalized_phone(): void
    {
        $user = User::factory()->create(['phone' => '+2348012345678']);

        $this->post('/login', ['identifier' => '+234 801 234 5678', 'password' => 'password'])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_invalid_and_unknown_credentials_use_the_same_message(): void
    {
        User::factory()->create(['email' => 'known@example.com']);

        $message = 'These credentials do not match our records.';

        $this->from('/login')->post('/login', [
            'identifier' => 'known@example.com',
            'password' => 'wrong-password',
        ])->assertSessionHasErrors(['identifier' => $message]);

        $this->from('/login')->post('/login', [
            'identifier' => 'unknown@example.com',
            'password' => 'wrong-password',
        ])->assertSessionHasErrors(['identifier' => $message]);

        $this->assertGuest();
    }

    public function test_inactive_user_cannot_authenticate(): void
    {
        $user = User::factory()->create(['status' => UserStatus::Inactive]);

        $this->post('/login', ['identifier' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('identifier');

        $this->assertGuest();
    }

    public function test_manually_locked_user_cannot_authenticate(): void
    {
        $user = User::factory()->create(['status' => UserStatus::Locked]);

        $this->post('/login', ['identifier' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('identifier');

        $this->assertGuest();
    }

    public function test_failed_attempts_increment_and_fifth_failure_temporarily_locks_account(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 5) as $attempt) {
            $this->post('/login', ['identifier' => $user->email, 'password' => 'wrong']);
            $this->assertSame($attempt, $user->fresh()->failed_login_attempts);
        }

        $this->assertTrue($user->fresh()->locked_until->isFuture());
        $this->assertDatabaseHas('security_events', ['user_id' => $user->id, 'event' => 'account_locked']);

        $this->post('/login', ['identifier' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('identifier');
        $this->assertGuest();
    }

    public function test_expired_lock_allows_login_and_success_resets_failure_state(): void
    {
        $user = User::factory()->create([
            'failed_login_attempts' => 5,
            'locked_until' => now()->subMinute(),
            'last_failed_login_at' => now()->subMinute(),
        ]);

        $this->post('/login', ['identifier' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard'));

        $user->refresh();
        $this->assertSame(0, $user->failed_login_attempts);
        $this->assertNull($user->locked_until);
        $this->assertNull($user->last_failed_login_at);
        $this->assertNotNull($user->last_login_at);
        $this->assertNotNull($user->last_login_ip);
    }

    public function test_password_and_pin_hashes_are_not_serialized(): void
    {
        $user = User::factory()->create(['quick_pin_hash' => Hash::make('1234')]);
        $array = $user->toArray();

        $this->assertArrayNotHasKey('password', $array);
        $this->assertArrayNotHasKey('quick_pin_hash', $array);
        $this->assertArrayNotHasKey('remember_token', $array);
    }

    public function test_failed_login_uses_a_database_row_lock(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $user = User::factory()->create();

        $this->post('/login', ['identifier' => $user->email, 'password' => 'wrong']);

        $this->assertTrue(collect($queries)->contains(
            fn (string $sql): bool => Str::contains(Str::lower($sql), 'for update')
        ));
    }
}
