<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\EnsureOwnerEmailIsVerified;
use App\Models\Customer;
use App\Models\User;
use App\Tenancy\CurrentBusiness;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The order a public signup owner moves through — verify the email, set or skip the quick PIN, then
 * the Business — enforced on the server for every route, whatever URL or form is submitted, and
 * forgiving of the harmless repeats a real person makes, such as opening the same link twice.
 */
class OnboardingGateTest extends TestCase
{
    use RefreshDatabase;

    /** Tables that hold no business or account state a gated request could have written. */
    private const BOOKKEEPING_TABLES = ['migrations', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'security_events'];

    /* ------------------------------------------------- A. bypassing verification */

    public function test_an_unverified_owner_cannot_reach_any_onboarding_stage_or_the_business(): void
    {
        $owner = $this->signUp();
        $customer = Customer::factory()->forBusiness($owner->business)->create();

        foreach ([
            route('onboarding.pin.edit'), route('onboarding.password.edit'), route('dashboard'), route('operations'),
            route('customers.index'), route('customers.show', $customer), route('profile.edit'), route('search', ['q' => $customer->name]),
        ] as $url) {
            $this->get($url)->assertRedirect(route('verification.notice'));
        }

        $this->getJson(route('search', ['q' => $customer->name]))->assertForbidden()->assertDontSee($customer->name);
        $this->get(route('verification.notice'))->assertOk()->assertDontSee($customer->name);
    }

    public function test_direct_onboarding_posts_from_an_unverified_owner_change_nothing(): void
    {
        $owner = $this->signUp();
        $before = $owner->fresh()->only(['password', 'quick_pin_hash', 'quick_pin_setup_completed', 'force_password_change', 'email_verified_at']);

        $this->post(route('onboarding.pin.store'), ['pin' => '1234', 'pin_confirmation' => '1234'])->assertRedirect(route('verification.notice'));
        $this->post(route('onboarding.pin.skip'))->assertRedirect(route('verification.notice'));
        $this->post(route('onboarding.password.update'), ['password' => 'Takeover99', 'password_confirmation' => 'Takeover99'])
            ->assertRedirect(route('verification.notice'));

        $this->assertSame($before, $owner->fresh()->only(array_keys($before)));
        $this->assertNull($owner->fresh()->quick_pin_hash);
        $this->assertFalse($owner->fresh()->hasVerifiedEmail());
        $this->assertSame(0, DB::table('security_events')->where('subject_user_id', $owner->id)->whereIn('event', ['pin_set', 'pin_skipped', 'password_changed'])->count());
    }

    public function test_every_gated_route_refuses_an_unverified_owner_and_writes_nothing(): void
    {
        $owner = $this->signUp();
        $before = $this->domainCounts();
        $checked = 0;

        foreach ($this->gatedRoutes() as $route) {
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $response = $this->call($method, $this->urlFor($route));
                $checked++;

                // A binding that cannot resolve answers 404 before the gate; neither discloses anything.
                $this->assertTrue(
                    $response->isRedirect(route('verification.notice')) || $response->getStatusCode() === 404,
                    "{$method} /{$route->uri()} answered {$response->getStatusCode()} to an unverified owner",
                );
            }
        }

        $this->assertGreaterThan(100, $checked, 'the sweep reached the whole application');
        $this->assertSame($before, $this->domainCounts(), 'no gated request wrote anything');
        $this->assertFalse($owner->fresh()->hasVerifiedEmail());
    }

    public function test_guests_are_sent_to_sign_in_and_nothing_is_written(): void
    {
        $before = $this->domainCounts();

        foreach ([['GET', 'onboarding.pin.edit'], ['GET', 'onboarding.password.edit'], ['GET', 'dashboard'], ['GET', 'verification.notice'],
            ['POST', 'onboarding.pin.store'], ['POST', 'onboarding.pin.skip'], ['POST', 'onboarding.password.update'], ['POST', 'verification.send']] as [$method, $name]) {
            $this->call($method, route($name), ['pin' => '1234', 'pin_confirmation' => '1234', 'password' => 'Takeover99', 'password_confirmation' => 'Takeover99'])
                ->assertRedirect(route('login'));
        }

        $this->assertGuest();
        $this->assertSame($before, $this->domainCounts());
    }

    /* ------------------------------------------- B. reopening a verification link */

    public function test_reopening_a_used_link_is_harmless_and_follows_the_remaining_onboarding(): void
    {
        $owner = $this->signUp();
        $link = $this->linkFor($owner);
        $tenants = $this->tenantCounts();

        $this->get($link)->assertRedirect(route('onboarding.pin.edit'));
        $verifiedAt = $owner->fresh()->email_verified_at;
        $this->assertNotNull($verifiedAt);

        $this->get($link)->assertRedirect(route('onboarding.pin.edit'))->assertSessionHas('status', 'Your email address is verified.');
        $this->get(route('onboarding.pin.edit'))->assertOk()->assertSee('Create your Quick PIN');

        $this->post(route('onboarding.pin.store'), ['pin' => '2468', 'pin_confirmation' => '2468'])->assertRedirect(route('dashboard'));
        $this->get($link)->assertRedirect(route('dashboard'));
        $this->get(route('dashboard'))->assertOk();

        $this->assertTrue($verifiedAt->equalTo($owner->fresh()->email_verified_at), 'verification is recorded once, at the first click');
        $this->assertSame(1, DB::table('security_events')->where('subject_user_id', $owner->id)->where('event', 'email_verified')->count());
        $this->assertSame($tenants, $this->tenantCounts(), 'no Business, owner, settings or subscription is created by a click');
    }

    public function test_a_reopened_link_keeps_signature_expiry_and_ownership_protection(): void
    {
        $owner = $this->signUp();
        $link = $this->linkFor($owner);
        $this->get($link)->assertRedirect(route('onboarding.pin.edit'));

        $this->get($link.'0')->assertForbidden();
        $this->get(str_replace('signature=', 'signature=0', $link))->assertForbidden();

        $this->travel(61)->minutes();
        $this->get($link)->assertForbidden();
        $this->travelBack();

        $this->post(route('logout'));
        $other = $this->signUp('other@gate.test', '08035550302');
        $this->get($link)->assertForbidden();
        $this->assertFalse($other->fresh()->hasVerifiedEmail());
    }

    public function test_a_signed_out_owner_opening_a_link_verifies_after_signing_in(): void
    {
        $owner = $this->signUp();
        $link = $this->linkFor($owner);
        $this->post(route('logout'));

        $this->get($link)->assertRedirect(route('login'));
        $this->assertFalse($owner->fresh()->hasVerifiedEmail(), 'a link never verifies without its own account signed in');

        $this->post(route('login.store'), ['identifier' => 'owner@gate.test', 'password' => 'Secret123'])->assertRedirect($this->pathOf($link));
        $this->get($this->pathOf($link))->assertRedirect(route('onboarding.pin.edit'));
        $this->assertTrue($owner->fresh()->hasVerifiedEmail());

        // An old link opened again while signed out, after verifying, leads straight back in.
        $this->post(route('logout'));
        $this->get($link)->assertRedirect(route('login'));
        $this->post(route('login.store'), ['identifier' => 'owner@gate.test', 'password' => 'Secret123'])->assertRedirect($this->pathOf($link));
        $this->get($this->pathOf($link))->assertRedirect(route('onboarding.pin.edit'));
        $this->assertSame(1, DB::table('security_events')->where('subject_user_id', $owner->id)->where('event', 'email_verified')->count());
    }

    /* --------------------------------------------------------- completed stages */

    public function test_a_verified_owner_is_moved_past_verification_and_cannot_resend(): void
    {
        $owner = $this->signUp();
        $this->get($this->linkFor($owner));
        Notification::fake();

        $this->get(route('verification.notice'))->assertRedirect(route('dashboard'));
        $this->get(route('dashboard'))->assertRedirect(route('onboarding.pin.edit'));
        $this->post(route('verification.send'))->assertRedirect(route('dashboard'))->assertSessionMissing('status');

        Notification::assertNothingSent();
    }

    public function test_an_owner_who_was_never_forced_cannot_use_the_onboarding_password_change(): void
    {
        $owner = $this->signUp();
        $this->get($this->linkFor($owner));
        $hash = $owner->fresh()->password;

        $this->get(route('onboarding.password.edit'))->assertRedirect(route('onboarding.pin.edit'));
        $this->post(route('onboarding.password.update'), ['password' => 'Takeover99', 'password_confirmation' => 'Takeover99'])
            ->assertRedirect(route('onboarding.pin.edit'));

        $this->assertSame($hash, $owner->fresh()->password);
        $this->assertTrue(Hash::check('Secret123', $owner->fresh()->password));
        $this->assertSame(0, DB::table('security_events')->where('subject_user_id', $owner->id)->where('event', 'password_changed')->count());
    }

    public function test_completed_stages_cannot_be_revisited_or_resubmitted_from_history(): void
    {
        $owner = $this->signUp();
        $this->get($this->linkFor($owner));
        $this->post(route('onboarding.pin.store'), ['pin' => '2468', 'pin_confirmation' => '2468'])->assertRedirect(route('dashboard'));
        $state = array_map('strval', $owner->fresh()->only(['password', 'quick_pin_hash', 'quick_pin_setup_completed', 'email_verified_at']));

        // What the back button replays: each earlier screen, then its form.
        $this->get(route('onboarding.pin.edit'))->assertRedirect(route('dashboard'));
        $this->get(route('verification.notice'))->assertRedirect(route('dashboard'));
        $this->get(route('onboarding.password.edit'))->assertRedirect(route('onboarding.pin.edit'));
        $this->post(route('onboarding.pin.store'), ['pin' => '1357', 'pin_confirmation' => '1357'])->assertRedirect(route('dashboard'));
        $this->post(route('onboarding.pin.skip'))->assertRedirect(route('dashboard'));
        $this->post(route('onboarding.password.update'), ['password' => 'Takeover99', 'password_confirmation' => 'Takeover99'])->assertRedirect(route('onboarding.pin.edit'));

        $this->assertSame($state, array_map('strval', $owner->fresh()->only(array_keys($state))));
        $this->assertSame(['pin_set'], DB::table('security_events')->where('subject_user_id', $owner->id)->whereIn('event', ['pin_set', 'pin_skipped', 'password_changed'])->pluck('event')->all());
    }

    public function test_onboarding_screens_are_not_kept_in_the_browser_cache(): void
    {
        $owner = $this->signUp();

        foreach (['verification.notice'] as $name) {
            $this->assertNoStore($this->get(route($name)));
        }

        $this->get($this->linkFor($owner));
        $this->assertNoStore($this->get(route('onboarding.pin.edit')));
    }

    /* ------------------------------------------------------------------- helpers */

    private function signUp(string $email = 'owner@gate.test', string $phone = '08035550301'): User
    {
        app(CurrentBusiness::class)->forget();

        $this->post(route('register.store'), [
            'owner_name' => 'Gate Owner', 'email' => $email, 'phone' => $phone, 'password' => 'Secret123',
            'password_confirmation' => 'Secret123', 'business_name' => 'Gate Ltd',
        ])->assertRedirect(route('verification.notice'));

        return User::query()->where('email', $email)->sole();
    }

    private function linkFor(User $user): string
    {
        $link = null;
        Notification::fake();
        $user->sendEmailVerificationNotification();
        Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification) use ($user, &$link): bool {
            $link = $notification->toMail($user)->actionUrl;

            return true;
        });

        return $link;
    }

    private function pathOf(string $url): string
    {
        $parts = parse_url($url);

        return $parts['path'].'?'.$parts['query'];
    }

    /** @return list<RoutingRoute> */
    private function gatedRoutes(): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RoutingRoute $route): bool => in_array(EnsureOwnerEmailIsVerified::class, Route::gatherRouteMiddleware($route), true))
            ->reject(fn (RoutingRoute $route): bool => str_starts_with((string) $route->getName(), 'verification.'))
            ->values()->all();
    }

    private function urlFor(RoutingRoute $route): string
    {
        return url(preg_replace('/\{[^}]+\}/', '1', $route->uri()));
    }

    /** @return array<string, int> */
    private function domainCounts(): array
    {
        return collect(DB::select('SHOW TABLES'))->map(fn ($row): string => array_values((array) $row)[0])
            ->diff(self::BOOKKEEPING_TABLES)->sort()->values()
            ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
    }

    /** @return array<string, int> */
    private function tenantCounts(): array
    {
        return collect(['businesses', 'business_settings', 'users', 'business_subscriptions'])
            ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
    }

    private function assertNoStore($response): void
    {
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }
}
