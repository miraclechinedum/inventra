<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Services\TimingSafePasswordVerifier;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class AuthenticationBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_configured_host_is_accepted(): void
    {
        $this->get('http://attacker.example/login')
            ->assertBadRequest()
            ->assertHeader('X-Frame-Options', 'DENY');

        $this->get(rtrim(config('app.url'), '/').'/login')->assertOk();
    }

    public function test_external_and_scheme_relative_intended_redirects_are_rejected(): void
    {
        foreach (['https://attacker.example/phish', '//attacker.example/phish', '/\\attacker.example', '/%5Cattacker.example'] as $intended) {
            $user = User::factory()->create();

            $this->withSession(['url.intended' => $intended])->post('/login', [
                'identifier' => $user->email,
                'password' => 'password',
            ])->assertRedirect(route('dashboard'));

            auth()->logout();
        }
    }

    public function test_malformed_app_url_fails_loudly(): void
    {
        config()->set('app.url', 'not-an-absolute-url');
        $this->withoutExceptionHandling();
        $this->expectException(LogicException::class);

        $this->get('/login');
    }

    public function test_same_origin_intended_redirect_is_preserved(): void
    {
        $user = User::factory()->create();

        $this->withSession(['url.intended' => '/dashboard?from=login'])->post('/login', [
            'identifier' => $user->email,
            'password' => 'password',
        ])->assertRedirect('/dashboard?from=login');
    }

    public function test_nigerian_phone_variants_share_canonical_storage_and_throttle_bucket(): void
    {
        config()->set('auth_security.login.max_attempts', 1);
        config()->set('auth_security.login.ip_max_attempts', 20);
        $user = User::factory()->create(['phone' => '0801-234-5678']);

        $this->post('/login', ['identifier' => '080 1234 5678', 'password' => 'wrong']);
        $this->post('/login', ['identifier' => '+234-801-234-5678', 'password' => 'wrong']);

        $this->assertSame('+2348012345678', $user->fresh()->phone);
        $this->assertSame(1, $user->fresh()->failed_login_attempts);
    }

    public function test_invalid_phone_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        User::factory()->create(['phone' => 'not-a-phone']);
    }

    public function test_equivalent_phone_formats_cannot_create_duplicate_accounts(): void
    {
        User::factory()->create(['phone' => '08012345678']);

        $this->expectException(QueryException::class);
        User::factory()->create(['phone' => '234 801 234 5678']);
    }

    public function test_user_mass_assignment_cannot_change_privileged_authentication_fields(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::SalesRep,
            'status' => UserStatus::Active,
            'force_password_change' => false,
        ]);

        $this->expectException(MassAssignmentException::class);

        $user->fill([
            'role' => UserRole::Admin->value,
            'status' => UserStatus::Locked->value,
            'force_password_change' => true,
            'failed_login_attempts' => 99,
        ]);
    }

    public function test_authentication_state_and_roles_are_refreshed_on_every_protected_request(): void
    {
        Route::middleware(['web', 'auth', 'active', 'role:admin'])
            ->get('/admin-regression', fn () => 'allowed');
        $user = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($user)->get('/admin-regression')->assertOk();

        DB::table('users')->where('id', $user->id)->update(['role' => UserRole::SalesRep->value]);
        $this->get('/admin-regression')->assertForbidden();

        Route::middleware(['web', 'auth', 'active', 'role:not-a-real-role'])
            ->get('/malformed-role-regression', fn () => 'never');
        $this->get('/malformed-role-regression')->assertForbidden();

        DB::table('users')->where('id', $user->id)->update(['status' => UserStatus::Locked->value]);
        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_timing_verifier_is_singleton_and_reuses_its_dummy_hash(): void
    {
        $first = app(TimingSafePasswordVerifier::class);
        $second = app(TimingSafePasswordVerifier::class);
        $property = new \ReflectionProperty($first, 'dummyHash');
        $dummyHash = $property->getValue($first);

        $this->assertSame($first, $second);
        $this->assertTrue(Hash::needsRehash($dummyHash) === false);
        $this->assertFalse($first->verify('wrong', null, false));
        $this->assertSame($dummyHash, $property->getValue($first));
    }
}
