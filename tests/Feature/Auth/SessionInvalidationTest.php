<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\UserSessionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SessionInvalidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_other_sessions_for_the_same_user_are_invalidated(): void
    {
        config()->set('session.driver', 'database');
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        foreach ([
            ['current', $user->id],
            ['other', $user->id],
            ['unrelated', $otherUser->id],
        ] as [$id, $userId]) {
            DB::table('sessions')->insert([
                'id' => $id,
                'user_id' => $userId,
                'payload' => '',
                'last_activity' => now()->timestamp,
            ]);
        }

        app(UserSessionManager::class)->invalidateOtherSessions($user, 'current');

        $this->assertDatabaseHas('sessions', ['id' => 'current']);
        $this->assertDatabaseMissing('sessions', ['id' => 'other']);
        $this->assertDatabaseHas('sessions', ['id' => 'unrelated']);
    }

    public function test_password_change_controller_revokes_same_user_sessions_without_cross_user_deletion(): void
    {
        config()->set('session.driver', 'database');
        $user = User::factory()->create(['force_password_change' => true]);
        $otherUser = User::factory()->create();

        foreach ([
            ['same-user-session', $user->id],
            ['other-user-session', $otherUser->id],
        ] as [$id, $userId]) {
            DB::table('sessions')->insert([
                'id' => $id,
                'user_id' => $userId,
                'payload' => '',
                'last_activity' => now()->timestamp,
            ]);
        }

        $this->actingAs($user)->post('/onboarding/password', [
            'password' => 'SecurePass9',
            'password_confirmation' => 'SecurePass9',
        ])->assertRedirect(route('onboarding.pin.edit'));

        $this->assertDatabaseMissing('sessions', ['id' => 'same-user-session']);
        $this->assertDatabaseHas('sessions', ['id' => 'other-user-session']);
    }
}
