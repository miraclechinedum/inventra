<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_dashboard_and_authenticated_user_leaves_login(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));

        $user = User::factory()->create();
        $this->actingAs($user)->get('/login')->assertRedirect(route('dashboard'));
    }

    public function test_role_middleware_allows_matching_role_and_rejects_wrong_role(): void
    {
        Route::middleware(['web', 'auth', 'role:admin,manager'])
            ->get('/role-check', fn () => 'allowed')
            ->name('test.role-check');

        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $salesRep = User::factory()->create(['role' => UserRole::SalesRep]);

        $this->actingAs($manager)->get('/role-check')->assertOk()->assertSee('allowed');
        $this->actingAs($salesRep)->get('/role-check')->assertForbidden();
        $this->assertDatabaseHas('security_events', ['user_id' => $salesRep->id, 'event' => 'access_denied']);
    }

    public function test_post_logout_invalidates_authentication_and_get_logout_is_unavailable(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();
        $this->get('/logout')->assertMethodNotAllowed();
        $this->assertDatabaseHas('security_events', ['user_id' => $user->id, 'event' => 'logout']);
    }

    public function test_auth_pages_keep_security_headers(): void
    {
        $this->get('/login')->assertOk()->assertHeader('X-Frame-Options', 'DENY');
        $this->get('/forgot-password')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->post('/login', ['identifier' => 'missing@example.com', 'password' => 'wrong'])
            ->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_initial_admin_command_creates_only_the_first_admin(): void
    {
        $this->artisan('inventra:create-admin')
            ->expectsQuestion('Name', 'Initial Admin')
            ->expectsQuestion('Email address', 'ADMIN@example.com')
            ->expectsQuestion('Phone number (optional)', '+2348011111111')
            ->expectsQuestion('Password', 'AdminPass9')
            ->expectsQuestion('Confirm password', 'AdminPass9')
            ->expectsOutput('Initial administrator created successfully.')
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'admin@example.com', 'role' => 'admin']);

        $this->artisan('inventra:create-admin')
            ->expectsOutput('An administrator already exists.')
            ->assertFailed();
    }
}
