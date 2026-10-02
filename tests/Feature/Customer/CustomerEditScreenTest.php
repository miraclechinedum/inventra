<?php

namespace Tests\Feature\Customer;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The Edit customer screen.
 *
 * The screen now shows WhatsApp consent, which the profile form deliberately cannot write:
 * UpdateCustomerRequest prohibits `whatsapp_opt_in` because consent is an audited transition that
 * stamps its own timestamps under a row lock. These tests hold that separation in place — the state
 * is displayed here, the change goes to the endpoint that already owns it, and saving the profile
 * never silently alters consent.
 */
class CustomerEditScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame(
            'inventra_test',
            DB::connection()->getDatabaseName(),
            'Refusing to run: these tests write, and this is not the test database.'
        );

        $this->manager = User::factory()->create(['role' => UserRole::Manager]);
    }

    private function customer(array $overrides = []): Customer
    {
        return Customer::factory()->create(array_merge([
            'first_name' => 'Emeka', 'last_name' => 'Obi',
            'phone' => '+2348012345678', 'email' => 'emeka@email.com',
            'tag' => 'Toyota Camry 2012', 'is_active' => true,
            'whatsapp_opt_in' => false, 'whatsapp_opt_in_at' => null, 'whatsapp_opt_out_at' => null,
        ], $overrides));
    }

    private function edit(Customer $customer, ?User $as = null): string
    {
        return $this->actingAs($as ?? $this->manager)
            ->get(route('customers.edit', $customer))
            ->assertOk()
            ->getContent();
    }

    // ── Structure ───────────────────────────────────────────────────────────────────────────────

    /** The breadcrumb names the customer and the page, and both lead back to the profile. */
    public function test_the_header_shows_the_customer_then_the_page(): void
    {
        $customer = $this->customer();

        $html = $this->edit($customer);

        $this->assertStringContainsString('Emeka Obi', $html);
        $this->assertStringContainsString('Edit customer', $html);
        // The close control and the name crumb both return to the profile.
        $this->assertStringContainsString(route('customers.show', $customer), $html);
    }

    public function test_the_stored_values_populate_every_field(): void
    {
        $customer = $this->customer();

        $html = $this->edit($customer);

        $this->assertStringContainsString('value="Emeka"', $html);
        $this->assertStringContainsString('value="Obi"', $html);
        $this->assertStringContainsString('value="+2348012345678"', $html);
        $this->assertStringContainsString('value="emeka@email.com"', $html);
        $this->assertStringContainsString('value="Toyota Camry 2012"', $html);
    }

    /**
     * The photo helper text claims only what is true.
     *
     * The design's copy reads "Shows in lists, profile & receipts", but no receipt template renders
     * a customer photograph. The screen says what it actually does instead.
     */
    public function test_the_photo_helper_text_does_not_claim_receipts(): void
    {
        // The hint accompanies Replace/Remove, which only exist once a photo is stored.
        $html = $this->edit($this->customer(['photo_path' => 'customer-photos/example.png']));

        $this->assertStringContainsString('Shows in lists &amp; profile.', $html);
        // Scoped to the hint itself: "receipts" legitimately appears elsewhere in the shell.
        preg_match('/<p class="cust-photo-hint">(.*?)<\/p>/s', $html, $hint);
        $this->assertNotEmpty($hint);
        $this->assertStringNotContainsString('receipt', $hint[1]);
    }

    // ── Consent display ─────────────────────────────────────────────────────────────────────────

    public function test_an_opted_in_customer_shows_the_consent_state_and_the_withdraw_action(): void
    {
        $customer = $this->customer(['whatsapp_opt_in' => true, 'whatsapp_opt_in_at' => now()]);

        $html = $this->edit($customer);

        $this->assertStringContainsString('Receive WhatsApp updates', $html);
        $this->assertStringContainsString('Customer consents to automated order &amp; follow-up messages.', $html);
        $this->assertStringContainsString('Withdraw consent', $html);
        // The hidden value is the OPPOSITE of the current state: the control toggles.
        $this->assertStringContainsString('name="opt_in" value="0"', $html);
    }

    /**
     * A customer who never consented is described as such.
     *
     * The green "consents to automated messages" copy must never render for someone who did not,
     * and having a phone number is not consent.
     */
    public function test_a_customer_without_consent_is_never_described_as_consenting(): void
    {
        $html = $this->edit($this->customer());

        $this->assertStringContainsString('This customer has not consented to automated messages.', $html);
        $this->assertStringNotContainsString('Customer consents to automated order', $html);
        $this->assertStringContainsString('Record consent', $html);
        $this->assertStringContainsString('name="opt_in" value="1"', $html);
    }

    /** The consent control posts to the existing audited endpoint, not to the profile form. */
    public function test_consent_posts_to_its_own_endpoint(): void
    {
        $customer = $this->customer();

        $html = $this->edit($customer);

        $this->assertStringContainsString(route('customers.consent', $customer), $html);
        // And it is a separate form, so it cannot be swept up in the profile save.
        $this->assertMatchesRegularExpression('/<form[^>]+whatsapp-consent/', $html);
    }

    /** Whoever may not change consent sees the state without a control they cannot use. */
    public function test_a_viewer_without_the_consent_policy_gets_no_control(): void
    {
        // A Sales Representative may not change consent for an INACTIVE customer.
        $rep = User::factory()->create(['role' => UserRole::SalesRep]);
        $customer = $this->customer(['is_active' => false]);

        $this->assertFalse($rep->can('changeConsent', $customer));

        // They also cannot reach the edit screen for one, which is the stronger guarantee.
        $this->actingAs($rep)->get(route('customers.edit', $customer))->assertForbidden();
    }

    // ── Separation of concerns ──────────────────────────────────────────────────────────────────

    /**
     * Saving the profile never changes consent.
     *
     * This is the property the split exists to protect: `whatsapp_opt_in` is prohibited on the
     * update path, so a crafted profile submission cannot grant consent without the audited
     * transition that stamps the timestamp.
     */
    public function test_a_profile_save_cannot_grant_consent(): void
    {
        $customer = $this->customer();

        $this->actingAs($this->manager)
            ->put(route('customers.update', $customer), [
                'first_name' => 'Emeka', 'last_name' => 'Obi', 'phone' => '+2348012345678',
                'email' => 'emeka@email.com', 'tag' => 'Toyota Camry 2012',
                'whatsapp_opt_in' => '1',
            ])
            ->assertSessionHasErrors('whatsapp_opt_in');

        $customer->refresh();
        $this->assertFalse((bool) $customer->whatsapp_opt_in);
        $this->assertNull($customer->whatsapp_opt_in_at);
    }

    /** An ordinary profile save still works and leaves consent exactly as it was. */
    public function test_an_ordinary_profile_save_preserves_consent(): void
    {
        $customer = $this->customer(['whatsapp_opt_in' => true, 'whatsapp_opt_in_at' => now()]);
        $optInAt = $customer->whatsapp_opt_in_at;

        $this->actingAs($this->manager)
            ->put(route('customers.update', $customer), [
                'first_name' => 'Emeka', 'last_name' => 'Obi', 'phone' => '+2348012345678',
                'email' => 'new@email.com', 'tag' => 'Toyota Camry 2012',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('customers.show', $customer));

        $customer->refresh();
        $this->assertSame('new@email.com', $customer->email);
        $this->assertTrue((bool) $customer->whatsapp_opt_in);
        $this->assertEquals($optInAt, $customer->whatsapp_opt_in_at);
    }

    /** The consent endpoint, reached from this screen, performs the real audited transition. */
    public function test_the_consent_control_records_an_audited_transition(): void
    {
        $customer = $this->customer();

        $this->actingAs($this->manager)
            ->post(route('customers.consent', $customer), ['opt_in' => '1'])
            ->assertRedirect();

        $customer->refresh();
        $this->assertTrue((bool) $customer->whatsapp_opt_in);
        $this->assertNotNull($customer->whatsapp_opt_in_at);
        $this->assertNull($customer->whatsapp_opt_out_at);

        $this->assertSame(1, DB::table('audit_logs')
            ->where('auditable_type', $customer->getMorphClass())
            ->where('auditable_id', $customer->id)
            ->where('action', 'customer_whatsapp_opted_in')
            ->count());
    }

    // ── Authorization ───────────────────────────────────────────────────────────────────────────

    public function test_a_guest_cannot_open_the_edit_screen(): void
    {
        $this->get(route('customers.edit', $this->customer()))->assertRedirect(route('login'));
    }

    public function test_a_guest_cannot_change_consent(): void
    {
        $customer = $this->customer();

        $this->post(route('customers.consent', $customer), ['opt_in' => '1'])
            ->assertRedirect(route('login'));

        $this->assertFalse((bool) $customer->refresh()->whatsapp_opt_in);
    }
}
