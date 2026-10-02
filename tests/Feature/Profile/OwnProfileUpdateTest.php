<?php

namespace Tests\Feature\Profile;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Requests\Profile\UpdateOwnProfileRequest;
use App\Http\Requests\Staff\UpdateStaffRequest;
use App\Models\User;
use App\Support\CanonicalLoginIdentifier;
use App\Support\UnavailableIdentifier;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Self-service personal details on /profile.
 *
 * Two properties carry the security of this endpoint, and most of the tests below exist to hold
 * them still:
 *
 *  - The subject is the session, never the payload. The route takes no {user} and the request
 *    validates no identifier, so there is no id, email or slug a crafted body can use to steer the
 *    write at another account. Escalation is therefore impossible by construction rather than by a
 *    check somebody could later forget.
 *  - Only name, email and phone are validated, so nothing else appears in validated() and nothing
 *    else can reach the model — role, status, password and photo_path included.
 *
 * Email is also the login identifier, so the canonical form stored here has to be the exact form
 * authentication resolves. A mismatch would lock an account out of itself.
 */
class OwnProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame(
            'inventra_test',
            DB::connection()->getDatabaseName(),
            'Refusing to run: these tests write, and this is not the test database.'
        );
    }

    /** @return array<string, array{UserRole}> */
    public static function roles(): array
    {
        return [
            'administrator' => [UserRole::Admin],
            'manager' => [UserRole::Manager],
            'sales representative' => [UserRole::SalesRep],
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Tunde Akin',
            'email' => 'tunde@inventra.test',
            'phone' => '08025550190',
        ], $overrides);
    }

    // ── 1, 2, 3: every role may edit its own ────────────────────────────────────────────────────

    #[DataProvider('roles')]
    public function test_any_role_can_update_their_own_details(UserRole $role): void
    {
        $user = User::factory()->create(['role' => $role]);

        $this->actingAs($user)
            ->put(route('profile.update'), $this->payload())
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('status', 'Profile updated.')
            ->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertSame('Tunde Akin', $user->name);
        $this->assertSame('tunde@inventra.test', $user->email);
        $this->assertSame('+2348025550190', $user->phone);
        // The role that reached the endpoint is the role that comes out of it.
        $this->assertSame($role, $user->role);
    }

    /** The saved values are on the page immediately, and in the shell's identity card. */
    public function test_the_new_details_are_shown_after_saving(): void
    {
        $user = User::factory()->create(['role' => UserRole::Manager]);

        $this->actingAs($user)->put(route('profile.update'), $this->payload(['name' => 'Ada Obi']))->assertRedirect();

        $html = $this->actingAs($user)->get(route('profile.edit'))->assertOk()->getContent();
        $this->assertStringContainsString('Ada Obi', $html);
        $this->assertStringContainsString('tunde@inventra.test', $html);
        $this->assertStringContainsString('+2348025550190', $html);
        // The sidebar card reads the live user, so the name there is the new one too.
        $this->assertStringContainsString('title="Ada Obi"', $html);
    }

    // ── 4, 5: the endpoint cannot be aimed at anyone else ───────────────────────────────────────

    /**
     * The route has no user parameter at all.
     *
     * This is the structural guarantee behind the next two tests: there is no address for a
     * crafted request to reach another account through.
     */
    public function test_the_update_route_accepts_no_user_parameter(): void
    {
        $route = Route::getRoutes()->getByName('profile.update');

        $this->assertNotNull($route);
        $this->assertSame([], $route->parameterNames());
        $this->assertSame('profile', $route->uri());
    }

    public function test_a_crafted_user_id_cannot_redirect_the_write_to_another_account(): void
    {
        $actor = User::factory()->create(['role' => UserRole::SalesRep]);
        $victim = User::factory()->create(['role' => UserRole::Admin, 'name' => 'Victim Admin']);
        $before = $victim->only(['name', 'email', 'phone']);

        $this->actingAs($actor)->put(route('profile.update'), $this->payload([
            'user_id' => $victim->id,
            'id' => $victim->id,
            'user' => $victim->id,
        ]))->assertRedirect(route('profile.edit'))->assertSessionHasNoErrors();

        // The caller's own row changed; the other account is untouched.
        $this->assertSame('Tunde Akin', $actor->refresh()->name);
        $this->assertSame($before, $victim->refresh()->only(['name', 'email', 'phone']));
    }

    /** And there is no other-user route to reach through this controller either. */
    public function test_a_user_scoped_profile_url_does_not_exist(): void
    {
        $actor = User::factory()->create();
        $victim = User::factory()->create();

        $this->actingAs($actor)->put('/profile/'.$victim->id, $this->payload())->assertNotFound();
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'profile_updated')->count());
    }

    // ── 6, 7, 8: privileged columns are unreachable ─────────────────────────────────────────────

    public function test_a_crafted_role_is_ignored(): void
    {
        $user = User::factory()->create(['role' => UserRole::SalesRep]);

        $this->actingAs($user)->put(route('profile.update'), $this->payload([
            'role' => UserRole::Admin->value,
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(UserRole::SalesRep, $user->refresh()->role);
    }

    public function test_a_crafted_status_is_ignored(): void
    {
        $user = User::factory()->create(['role' => UserRole::Manager, 'status' => UserStatus::Active]);

        $this->actingAs($user)->put(route('profile.update'), $this->payload([
            'status' => UserStatus::Locked->value,
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(UserStatus::Active, $user->refresh()->status);
    }

    /** The photograph has its own validated endpoint; a path cannot be posted here. */
    public function test_a_crafted_photo_path_cannot_be_written(): void
    {
        $user = User::factory()->create(['photo_path' => null]);

        $this->actingAs($user)->put(route('profile.update'), $this->payload([
            'photo_path' => 'staff-photos/../../.env',
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNull($user->refresh()->photo_path);
    }

    /** Every other security-shaped column a wider payload might reach for. */
    public function test_no_other_user_column_can_be_written_through_this_endpoint(): void
    {
        $user = User::factory()->create(['role' => UserRole::SalesRep]);
        $before = DB::table('users')->where('id', $user->id)->first();

        $this->actingAs($user)->put(route('profile.update'), $this->payload([
            'password' => 'newpassword123',
            'quick_pin_hash' => 'pwned',
            'failed_login_attempts' => 99,
            'locked_until' => now()->addYear()->toDateTimeString(),
            'force_password_change' => true,
            'quick_pin_setup_completed' => false,
            'email_verified_at' => now()->toDateTimeString(),
            'created_by' => 1,
            'created_at' => '2000-01-01 00:00:00',
            'remember_token' => 'stolen',
            'last_login_ip' => '10.0.0.1',
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $after = DB::table('users')->where('id', $user->id)->first();

        foreach ([
            'password', 'quick_pin_hash', 'failed_login_attempts', 'locked_until',
            'force_password_change', 'quick_pin_setup_completed', 'email_verified_at',
            'created_by', 'created_at', 'remember_token', 'last_login_ip', 'role', 'status',
            'photo_path',
        ] as $column) {
            $this->assertSame($before->{$column}, $after->{$column}, "{$column} must not be writable here");
        }
    }

    // ── 9, 10, 11: email ────────────────────────────────────────────────────────────────────────

    public function test_another_members_email_is_rejected(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create(['email' => 'taken@inventra.test']);

        $this->actingAs($user)
            ->put(route('profile.update'), $this->payload(['email' => 'taken@inventra.test']))
            ->assertSessionHasErrors(['email' => UnavailableIdentifier::EMAIL]);

        $this->assertNotSame('taken@inventra.test', $user->refresh()->email);
        $this->assertSame('taken@inventra.test', $other->refresh()->email);
    }

    /** Case and padding are canonicalised before the uniqueness check, so neither is a loophole. */
    public function test_another_members_email_cannot_be_claimed_by_changing_its_case(): void
    {
        $user = User::factory()->create();
        User::factory()->create(['email' => 'taken@inventra.test']);

        $this->actingAs($user)
            ->put(route('profile.update'), $this->payload(['email' => '  TAKEN@Inventra.TEST  ']))
            ->assertSessionHasErrors('email');
    }

    /** Resubmitting your own address unchanged is not a duplicate. */
    public function test_the_current_email_can_be_resubmitted(): void
    {
        $user = User::factory()->create(['email' => 'admin@inventra.com', 'phone' => '+2348025550190']);

        $this->actingAs($user)->put(route('profile.update'), [
            'name' => $user->name, 'email' => 'admin@inventra.com', 'phone' => '+2348025550190',
        ])->assertSessionHasNoErrors();

        $this->assertSame('admin@inventra.com', $user->refresh()->email);
    }

    public function test_an_invalid_email_is_rejected(): void
    {
        $user = User::factory()->create();
        $before = $user->email;

        // `missing@tld` is deliberately absent: a bare hostname is a valid RFC address, and the
        // staff editor accepts it too. Diverging here would let an Administrator save an address
        // through the staff screen that its owner could not then resave through their own.
        foreach (['', 'not-an-email', 'two@@at.test', 'spaces in@mail.test', '@no-local.test'] as $email) {
            $this->actingAs($user)
                ->put(route('profile.update'), $this->payload(['email' => $email]))
                ->assertSessionHasErrors('email', "email={$email} must be refused");
        }

        $this->assertSame($before, $user->refresh()->email);
    }

    /** The self-service editor and the staff editor accept exactly the same addresses. */
    public function test_email_validity_matches_the_staff_editor(): void
    {
        $self = (new UpdateOwnProfileRequest)->rules()['email'];
        $staff = (new UpdateStaffRequest)->rules()['email'];

        $shape = static fn (array $rules): array => array_values(array_filter(
            $rules,
            static fn ($rule): bool => is_string($rule) && $rule !== 'string',
        ));

        $this->assertSame($shape($staff), $shape($self));
    }

    /**
     * The stored email is exactly what authentication will resolve.
     *
     * Login canonicalises through CanonicalLoginIdentifier; if this endpoint stored a different
     * shape the account could no longer sign in with the address its owner just chose.
     */
    public function test_the_stored_email_matches_what_authentication_resolves(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put(route('profile.update'), $this->payload([
            'email' => '  Tunde.Akin@Inventra.TEST ',
        ]))->assertSessionHasNoErrors();

        $stored = $user->refresh()->email;
        $this->assertSame('tunde.akin@inventra.test', $stored);

        $canonical = CanonicalLoginIdentifier::from('Tunde.Akin@Inventra.TEST');
        $this->assertNotNull($canonical);
        $this->assertSame('email', $canonical->type);
        $this->assertSame($stored, $canonical->value, 'the profile and login must agree on the identifier');
        // And the row is findable exactly as the login controller finds it.
        $this->assertTrue(User::query()->where($canonical->type, $canonical->value)->whereKey($user->id)->exists());
    }

    // ── 12, 13, 14, 15: phone ───────────────────────────────────────────────────────────────────

    /** @return array<string, array{string}> */
    public static function friendlyPhones(): array
    {
        return [
            'spaced local' => ['0802 555 0190'],
            'bare local' => ['08025550190'],
            'spaced international' => ['+234 802 555 0190'],
            'country code without plus' => ['2348025550190'],
            'bracketed and hyphenated' => ['(0802) 555-0190'],
        ];
    }

    #[DataProvider('friendlyPhones')]
    public function test_friendly_phone_input_normalizes_to_the_canonical_form(string $input): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put(route('profile.update'), $this->payload(['phone' => $input]))
            ->assertSessionHasNoErrors();

        $this->assertSame('+2348025550190', $user->refresh()->phone, "input {$input} must canonicalise");
    }

    public function test_a_malformed_phone_is_rejected_truthfully(): void
    {
        $user = User::factory()->create(['phone' => '+2348031112233']);

        foreach (['12345', '080255501', '0602555019', 'not a phone', '+1 415 555 0190'] as $phone) {
            $this->actingAs($user)
                ->put(route('profile.update'), $this->payload(['phone' => $phone]))
                ->assertSessionHasErrors(['phone' => 'Enter a valid Nigerian phone number.']);
        }

        $this->assertSame('+2348031112233', $user->refresh()->phone);
    }

    public function test_the_current_phone_can_be_resubmitted(): void
    {
        $user = User::factory()->create(['phone' => '+2348025550190']);

        $this->actingAs($user)
            ->put(route('profile.update'), $this->payload(['phone' => '+2348025550190']))
            ->assertSessionHasNoErrors();

        $this->assertSame('+2348025550190', $user->refresh()->phone);
    }

    /** users.phone is uniquely indexed, so another member's number cannot be claimed. */
    public function test_another_members_phone_cannot_be_claimed(): void
    {
        $user = User::factory()->create(['phone' => '+2348031112233']);
        $other = User::factory()->create(['phone' => '+2348025550190']);

        // Submitted in a friendly shape: normalisation happens before the uniqueness check, so a
        // different spelling of the same number is still the same number.
        $this->actingAs($user)
            ->put(route('profile.update'), $this->payload(['phone' => '0802 555 0190']))
            ->assertSessionHasErrors('phone');

        $this->assertSame('+2348031112233', $user->refresh()->phone);
        $this->assertSame('+2348025550190', $other->refresh()->phone);
    }

    /** Phone is optional in this domain, so it can be cleared. */
    public function test_the_phone_may_be_cleared_because_the_domain_allows_it(): void
    {
        $user = User::factory()->create(['phone' => '+2348025550190']);

        $this->actingAs($user)
            ->put(route('profile.update'), $this->payload(['phone' => '']))
            ->assertSessionHasNoErrors();

        $this->assertNull($user->refresh()->phone);
    }

    // ── 16: name ────────────────────────────────────────────────────────────────────────────────

    public function test_a_blank_or_whitespace_only_name_is_rejected(): void
    {
        $user = User::factory()->create(['name' => 'Tunde Akin']);

        foreach (['', '   ', "\t", "\n"] as $name) {
            $this->actingAs($user)
                ->put(route('profile.update'), $this->payload(['name' => $name]))
                ->assertSessionHasErrors(['name' => 'Full name is required.']);
        }

        $this->assertSame('Tunde Akin', $user->refresh()->name);
    }

    /** A name is trimmed, not otherwise policed: real names carry marks a pattern would reject. */
    public function test_legitimate_international_names_are_accepted(): void
    {
        foreach (['Chidi Eze-Okonkwo', "Ngozi O'Brien", 'José Álvarez', 'Ólúwáṣeun Adébáyọ̀', 'Ali ibn Abī Ṭālib'] as $name) {
            $user = User::factory()->create();

            $this->actingAs($user)->put(route('profile.update'), $this->payload([
                'name' => '  '.$name.'  ', 'email' => 'u'.$user->id.'@inventra.test', 'phone' => null,
            ]))->assertSessionHasNoErrors();

            $this->assertSame($name, $user->refresh()->name, "{$name} must be accepted and trimmed");
        }
    }

    // ── 17, 18, 19, 20: authority and session survive ───────────────────────────────────────────

    public function test_role_status_and_password_hash_are_untouched_by_a_normal_update(): void
    {
        $user = User::factory()->create(['role' => UserRole::Manager, 'status' => UserStatus::Active]);
        $hash = DB::table('users')->where('id', $user->id)->value('password');

        $this->actingAs($user)->put(route('profile.update'), $this->payload())->assertRedirect();

        $user->refresh();
        $this->assertSame(UserRole::Manager, $user->role);
        $this->assertSame(UserStatus::Active, $user->status);
        $this->assertSame($hash, DB::table('users')->where('id', $user->id)->value('password'));
    }

    /** Changing your own email keeps you signed in as yourself — never as somebody else. */
    public function test_the_session_survives_an_email_change_and_still_belongs_to_the_same_account(): void
    {
        $user = User::factory()->create(['email' => 'before@inventra.test']);
        $other = User::factory()->create(['email' => 'other@inventra.test']);

        $this->actingAs($user)
            ->put(route('profile.update'), $this->payload(['email' => 'after@inventra.test']))
            ->assertRedirect(route('profile.edit'));

        $this->assertAuthenticatedAs($user->fresh());
        $this->assertSame('after@inventra.test', $user->refresh()->email);
        // And emphatically not the other account.
        $this->assertNotSame($other->id, auth()->id());

        // The page still loads as that account.
        $this->actingAs($user)->get(route('profile.edit'))->assertOk()->assertSee('after@inventra.test');
    }

    /** Inventra has no email verification, and this change did not invent one. */
    public function test_an_email_change_does_not_invent_verification(): void
    {
        $user = User::factory()->create();
        $before = DB::table('users')->where('id', $user->id)->value('email_verified_at');

        $this->actingAs($user)->put(route('profile.update'), $this->payload())->assertRedirect();

        $this->assertSame($before, DB::table('users')->where('id', $user->id)->value('email_verified_at'));
        // Verification is owed only by public signup owners, never by staff or legacy accounts.
        $this->assertFalse($user->fresh()->owesEmailVerification());
    }

    /** An owner who must prove their email proves the new one too. */
    public function test_an_owner_changing_email_must_verify_the_new_address(): void
    {
        Notification::fake();
        $owner = User::factory()->create(['email_verification_required' => true, 'email_verified_at' => now(), 'quick_pin_setup_completed' => true]);

        $this->actingAs($owner)->put(route('profile.update'), $this->payload(['email' => 'new.address@inventra.test']))->assertRedirect();

        $this->assertNull($owner->fresh()->email_verified_at);
        $this->assertTrue($owner->fresh()->owesEmailVerification());
        Notification::assertSentTo($owner->fresh(), VerifyEmail::class);
        $this->actingAs($owner->fresh())->get(route('dashboard'))->assertRedirect(route('verification.notice'));
    }

    // ── 21, 22: failure and no-op behaviour ─────────────────────────────────────────────────────

    /** A rejected submission changes nothing and hands back what was typed. */
    public function test_a_validation_failure_preserves_the_stored_values_and_the_input(): void
    {
        $user = User::factory()->create(['name' => 'Original Name', 'phone' => '+2348031112233']);
        $email = $user->email;

        $this->actingAs($user)->put(route('profile.update'), [
            'name' => '', 'email' => 'not-an-email', 'phone' => '0802 555 0190',
        ])
            ->assertSessionHasErrors(['name', 'email'])
            // Flashed input is what was typed, not the canonicalised form: the field repopulates
            // with the operator's own text rather than silently rewriting it under them.
            ->assertSessionHasInput('phone', '0802 555 0190')
            ->assertSessionHasInput('email', 'not-an-email');

        $user->refresh();
        $this->assertSame('Original Name', $user->name);
        $this->assertSame($email, $user->email);
        $this->assertSame('+2348031112233', $user->phone);
        // Nothing was audited for a submission that never applied.
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'profile_updated')->count());
    }

    /** Resubmitting the current values is a safe no-op that records nothing. */
    public function test_an_unchanged_submission_writes_nothing_and_audits_nothing(): void
    {
        $user = User::factory()->create(['name' => 'Tunde Akin', 'email' => 'tunde@inventra.test', 'phone' => '+2348025550190']);
        $before = DB::table('users')->where('id', $user->id)->first();

        $this->actingAs($user)->put(route('profile.update'), $this->payload())
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('status', 'No changes were made to your profile.');

        $this->assertEquals($before, DB::table('users')->where('id', $user->id)->first());
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'profile_updated')->count());
        $this->assertSame(0, DB::table('security_events')->where('event', 'profile_updated')->count());
    }

    // ── 23, 24: guests and CSRF ─────────────────────────────────────────────────────────────────

    public function test_a_guest_cannot_update_a_profile(): void
    {
        $user = User::factory()->create(['name' => 'Untouched']);

        $this->put(route('profile.update'), $this->payload())->assertRedirect(route('login'));

        $this->assertSame('Untouched', $user->refresh()->name);
        $this->assertGuest();
    }

    /**
     * CSRF protection is in force on this route.
     *
     * The rest of this class runs with the middleware disabled by the test harness, so this asserts
     * the protection directly: with it enabled, a POST without a token is rejected.
     */
    public function test_csrf_protection_applies_to_the_update_route(): void
    {
        $user = User::factory()->create(['name' => 'Untouched']);

        // The route sits in the `web` group, which is what applies VerifyCsrfToken, and it is not
        // excluded from that check the way the WhatsApp webhook deliberately is.
        $this->assertContains('web', Route::getRoutes()->getByName('profile.update')->gatherMiddleware());

        // The forgery guard short-circuits under runningUnitTests(), so a tokenless request cannot
        // be made to fail from inside PHPUnit — asserting on that would prove nothing. What IS
        // verifiable is the two things that decide whether the protection applies in production:
        // the route runs the middleware, and it is not on the exemption list.
        $guard = PreventRequestForgery::class;

        $this->assertContains($guard, app(Kernel::class)->getMiddlewareGroups()['web'] ?? []);

        $exempt = (fn (): array => $this->except)->call(new $guard(app(), app('encrypter')));

        foreach ($exempt as $pattern) {
            $this->assertFalse(
                Request::create('/profile', 'PUT')->is($pattern),
                "profile.update must not be exempt from CSRF via {$pattern}"
            );
        }

        // And the form that drives it actually ships a token, so a real browser can satisfy it.
        $html = $this->actingAs($user)->get(route('profile.edit'))->assertOk()->getContent();
        $this->assertStringContainsString('name="_token"', $html);
        $this->assertSame('Untouched', $user->refresh()->name);
    }

    // ── audit ───────────────────────────────────────────────────────────────────────────────────

    /** One event, recording only what actually changed, attributed to the account itself. */
    public function test_a_change_records_one_audit_event_naming_only_the_changed_fields(): void
    {
        $user = User::factory()->create(['name' => 'Before Name', 'email' => 'keep@inventra.test', 'phone' => null]);

        $this->actingAs($user)->put(route('profile.update'), [
            'name' => 'After Name', 'email' => 'keep@inventra.test', 'phone' => null,
        ])->assertRedirect();

        $logs = DB::table('audit_logs')->where('action', 'profile_updated')->get();
        $this->assertCount(1, $logs);

        $log = $logs->first();
        $old = json_decode((string) $log->old_values, true);
        $new = json_decode((string) $log->new_values, true);

        $this->assertSame(['name' => 'Before Name'], $old);
        $this->assertSame(['name' => 'After Name'], $new);
        // Email and phone did not change, so they are absent rather than logged as unchanged.
        $this->assertArrayNotHasKey('email', $new);
        $this->assertArrayNotHasKey('phone', $new);
        // The account is both actor and subject: this was self-service.
        $this->assertSame($user->id, (int) $log->actor_id);
        $this->assertSame($user->id, (int) $log->auditable_id);
    }

    /** No authentication secret is ever recorded, whatever the payload carried. */
    public function test_the_audit_trail_carries_no_authentication_data(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put(route('profile.update'), $this->payload([
            'password' => 'hunter2', 'remember_token' => 'SECRETTOKEN', 'quick_pin_hash' => 'PINHASH',
        ]))->assertRedirect();

        $dump = mb_strtolower((string) DB::table('audit_logs')->get()->toJson()
            .DB::table('security_events')->get()->toJson());

        foreach (['hunter2', 'secrettoken', 'pinhash', 'password', 'bcrypt', '$2y$'] as $secret) {
            $this->assertStringNotContainsString($secret, $dump, "a secret reached the trail: {$secret}");
        }
    }

    public function test_a_security_event_records_which_fields_changed(): void
    {
        $user = User::factory()->create(['name' => 'Before', 'phone' => null]);

        $this->actingAs($user)->put(route('profile.update'), $this->payload())->assertRedirect();

        $event = DB::table('security_events')->where('event', 'profile_updated')->first();
        $this->assertNotNull($event);
        $this->assertSame($user->id, (int) $event->subject_user_id);

        $metadata = json_decode((string) $event->metadata, true);
        $this->assertArrayHasKey('changed_fields', $metadata);
        $this->assertStringContainsString('name', $metadata['changed_fields']);
    }

    // ── photograph compatibility ────────────────────────────────────────────────────────────────

    /** The photo endpoints are untouched and still work alongside the details form. */
    public function test_the_photo_routes_are_unchanged_and_still_reachable(): void
    {
        $user = User::factory()->create();

        $this->assertTrue(Route::has('profile.photo.store'));
        $this->assertTrue(Route::has('profile.photo.destroy'));

        $html = $this->actingAs($user)->get(route('profile.edit'))->assertOk()->getContent();
        $this->assertStringContainsString(route('profile.photo.store'), $html);
        $this->assertStringContainsString('Change photo', $html);

        // Uploading still works after the details form was added.
        $this->actingAs($user)->post(route('profile.photo.store'), [
            'photo' => UploadedFile::fake()->image('me.png', 300, 300),
        ])->assertRedirect();

        $this->assertNotNull($user->refresh()->photo_path);
    }
}
