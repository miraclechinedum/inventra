<?php

namespace Tests\Feature\Auth;

use App\Actions\Business\ProvisionBusiness;
use App\Enums\UserRole;
use App\Models\Business;
use App\Models\User;
use App\Support\UnavailableIdentifier;
use App\Tenancy\CurrentBusiness;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Correcting a mistyped signup email before it is verified: only by the account itself, only with
 * its current password, only to an available address — and the old address's link stops working.
 */
class EmailCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Secret123';

    private int $sequence = 0;

    public function test_the_notice_shows_the_address_and_offers_the_correction(): void
    {
        $owner = $this->unverifiedOwner('typo@exmaple.test');

        $this->actingAs($owner)->get(route('verification.notice'))->assertOk()
            ->assertSee('typo@exmaple.test')->assertSee('Resend verification email')
            ->assertSee('Change email address')->assertSee('name="current_password"', false);
    }

    public function test_an_unverified_owner_corrects_their_address_and_gets_a_fresh_link(): void
    {
        Notification::fake();
        $owner = $this->unverifiedOwner('typo@exmaple.test');
        $oldLink = $this->link($owner);
        $this->actingAs($owner)->get(route('verification.notice'));
        $session = session()->getId();

        $this->correct($owner, ['email' => '  Right@Example.TEST '])
            ->assertRedirect(route('verification.notice'))
            ->assertSessionHas('status', 'Your email address was updated. We sent a new verification link to it.');

        $owner->refresh();
        $this->assertSame(['right@example.test', null, true], [$owner->email, $owner->email_verified_at, (bool) $owner->email_verification_required]);
        $this->assertNotSame($session, session()->getId(), 'the session is regenerated');
        Notification::assertSentTo($owner, VerifyEmail::class);

        // The link for the old address cannot verify the new one; a fresh link can.
        $this->actingAs($owner)->get($oldLink)->assertForbidden();
        $this->assertFalse($owner->fresh()->hasVerifiedEmail());
        $this->actingAs($owner)->get($this->link($owner->fresh()))->assertRedirect();
        $this->assertTrue($owner->fresh()->hasVerifiedEmail());
    }

    public function test_the_current_password_is_required_and_must_be_right(): void
    {
        $owner = $this->unverifiedOwner('typo@exmaple.test');

        $this->correct($owner, ['current_password' => null])->assertSessionHasErrors(['current_password' => 'Enter your current password.']);
        $this->correct($owner, ['current_password' => 'Wrong1234'])->assertSessionHasErrors(['current_password' => 'That password is not correct.']);

        $this->assertSame('typo@exmaple.test', $owner->fresh()->email);
    }

    public function test_malformed_and_array_shaped_addresses_are_refused(): void
    {
        $owner = $this->unverifiedOwner('typo@exmaple.test');

        foreach (['not-an-email', '', ['a@b.test'], ['x' => 'y']] as $email) {
            $this->correct($owner, ['email' => $email])->assertSessionHasErrors('email');
        }

        $this->assertSame('typo@exmaple.test', $owner->fresh()->email);
    }

    public function test_an_unavailable_address_reads_the_same_whoever_holds_it(): void
    {
        $owner = $this->unverifiedOwner('typo@exmaple.test');
        $otherBusiness = User::factory()->create(['email' => 'held@elsewhere.test']);
        $colleague = User::factory()->forBusiness($owner->business)->create(['email' => 'held@colleague.test']);

        $this->correct($owner, ['email' => $otherBusiness->email])->assertSessionHasErrors(['email' => UnavailableIdentifier::EMAIL]);
        $this->correct($owner, ['email' => $colleague->email])->assertSessionHasErrors(['email' => UnavailableIdentifier::EMAIL]);

        $this->assertSame('typo@exmaple.test', $owner->fresh()->email);
    }

    public function test_nothing_but_the_address_can_be_submitted(): void
    {
        $owner = $this->unverifiedOwner('typo@exmaple.test');
        $victim = $this->unverifiedOwner('victim@other.test');

        $this->correct($owner, [
            'user_id' => $victim->id, 'business_id' => $victim->business_id, 'email_verified_at' => now()->toDateTimeString(),
            'email_verification_required' => false, 'verification_required' => false, 'role' => 'manager', 'status' => 'inactive',
        ])->assertSessionHasErrors(['user_id', 'business_id', 'email_verified_at', 'email_verification_required', 'verification_required', 'role', 'status']);

        $this->assertSame(['typo@exmaple.test', UserRole::Admin], [$owner->fresh()->email, $owner->fresh()->role]);
        $this->assertSame('victim@other.test', $victim->fresh()->email);
    }

    public function test_it_only_ever_changes_the_signed_in_account(): void
    {
        Notification::fake();
        $owner = $this->unverifiedOwner('typo@exmaple.test');
        $other = $this->unverifiedOwner('other@owner.test');

        $this->correct($other, ['email' => 'other.fixed@owner.test'])->assertRedirect();

        $this->assertSame(['typo@exmaple.test', 'other.fixed@owner.test'], [$owner->fresh()->email, $other->fresh()->email]);
    }

    public function test_a_verified_or_staff_account_cannot_use_the_correction(): void
    {
        $verified = $this->unverifiedOwner('done@owner.test');
        $verified->forceFill(['email_verified_at' => now()])->save();
        $staff = User::factory()->create(['email' => 'staff@legacy.test', 'password' => self::PASSWORD, 'quick_pin_setup_completed' => true]);

        $this->correct($verified->fresh(), ['email' => 'changed@owner.test'])->assertForbidden();
        $this->correct($staff, ['email' => 'changed@legacy.test'])->assertForbidden();

        $this->assertSame(['done@owner.test', 'staff@legacy.test'], [$verified->fresh()->email, $staff->fresh()->email]);
    }

    public function test_a_mail_failure_keeps_the_corrected_address(): void
    {
        // A mailer that cannot connect: sending throws, and the correction must stand regardless.
        config(['mail.default' => 'unreachable', 'mail.mailers.unreachable' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1, 'timeout' => 1]]);
        $owner = $this->unverifiedOwner('typo@exmaple.test');

        $this->correct($owner, ['email' => 'right@example.test'])->assertRedirect(route('verification.notice'));

        $this->assertSame('right@example.test', $owner->fresh()->email);
    }

    public function test_the_password_is_never_persisted_and_the_address_is_not_recorded(): void
    {
        Notification::fake();
        $owner = $this->unverifiedOwner('typo@exmaple.test');

        $this->correct($owner, ['email' => 'right@example.test'])->assertRedirect();

        foreach (['audit_logs', 'security_events', 'sessions'] as $table) {
            foreach (DB::table($table)->get() as $row) {
                $stored = json_encode($row).base64_decode((string) ($row->payload ?? ''));
                $this->assertStringNotContainsString(self::PASSWORD, $stored, "{$table} must never hold the password");
            }
        }

        $evidence = DB::table('audit_logs')->where('action', 'unverified_email_corrected')->sole();
        $this->assertStringNotContainsString('right@example.test', json_encode($evidence).'');
        $this->assertStringNotContainsString('typo@exmaple.test', (string) DB::table('security_events')->where('event', 'unverified_email_corrected')->value('metadata'));
    }

    /* -------------------------------------------------------------------- helpers */

    private function unverifiedOwner(string $email): User
    {
        auth()->forgetGuards();
        app(CurrentBusiness::class)->forget();

        try {
            $owner = app(ProvisionBusiness::class)->execute([
                'business_name' => 'Business '.$email, 'owner_name' => 'Owner', 'email' => $email,
                'phone' => User::normalizePhone('0803555'.str_pad((string) (++$this->sequence), 4, '0', STR_PAD_LEFT)), 'password' => self::PASSWORD,
            ]);
        } finally {
            app(CurrentBusiness::class)->set(Business::query()->orderBy('id')->firstOrFail());
        }

        return $owner->fresh();
    }

    /** @param array<string, mixed> $overrides */
    private function correct(User $user, array $overrides = [])
    {
        return $this->actingAs($user)->put(route('verification.email.update'), array_merge([
            'email' => 'right@example.test', 'current_password' => self::PASSWORD,
        ], $overrides));
    }

    private function link(User $user): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);
    }
}
