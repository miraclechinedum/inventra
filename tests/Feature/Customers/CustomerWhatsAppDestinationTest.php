<?php

namespace Tests\Feature\Customers;

use App\Actions\WhatsAppAutomation\WhatsAppAutomationEligibility;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Where a WhatsApp message goes, and whether it may be sent at all.
 *
 * Two separate questions that this module keeps separate:
 *
 *   effectiveWhatsAppPhone()   the destination — `whatsapp_phone` when set, `phone` otherwise
 *   whatsapp_opt_in            the permission — consent, which a number never implies
 *
 * A customer can have a perfectly good WhatsApp number and still not be contactable, because they
 * never agreed to be. These check that adding the destination column did not quietly turn having a
 * number into permission to use it.
 */
class CustomerWhatsAppDestinationTest extends TestCase
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

    private function consenting(array $attributes = []): Customer
    {
        return Customer::factory()->create(array_merge([
            'is_active' => true,
            'phone' => '+2348012345678',
            'whatsapp_opt_in' => true,
            'whatsapp_opt_in_at' => now(),
            'whatsapp_opt_out_at' => null,
        ], $attributes));
    }

    /** A customer predating the column: null means "reach them on their ordinary phone". */
    public function test_a_null_whatsapp_phone_falls_back_to_the_ordinary_phone(): void
    {
        $customer = $this->consenting(['whatsapp_phone' => null]);

        $this->assertSame('+2348012345678', $customer->effectiveWhatsAppPhone());
        $this->assertTrue($customer->whatsAppUsesPhone());
    }

    public function test_a_separate_whatsapp_phone_is_used_when_set(): void
    {
        $customer = $this->consenting(['whatsapp_phone' => '+2348099999999']);

        $this->assertSame('+2348099999999', $customer->effectiveWhatsAppPhone());
        $this->assertFalse($customer->whatsAppUsesPhone());
        // And the ordinary phone is untouched — the two are different facts.
        $this->assertSame('+2348012345678', $customer->phone);
    }

    /**
     * Consent governs sending, whatever the destination.
     *
     * This is the check that matters most: a reachable number must never become a reason to send.
     */
    public function test_a_customer_without_consent_is_not_contactable_however_good_the_number(): void
    {
        $optedOut = Customer::factory()->create([
            'is_active' => true,
            'phone' => '+2348012345678',
            'whatsapp_phone' => '+2348099999999',
            'whatsapp_opt_in' => false,
            'whatsapp_opt_in_at' => null,
        ]);

        // A perfectly usable destination...
        $this->assertSame('+2348099999999', $optedOut->effectiveWhatsAppPhone());
        // ...and still no permission to use it.
        $this->assertFalse(WhatsAppAutomationEligibility::permitsCustomer($optedOut));
    }

    public function test_consent_with_no_alternate_number_is_eligible_on_the_ordinary_phone(): void
    {
        $customer = $this->consenting(['whatsapp_phone' => null]);

        $this->assertTrue(WhatsAppAutomationEligibility::permitsCustomer($customer));
        $this->assertSame($customer->phone, $customer->effectiveWhatsAppPhone());
    }

    public function test_consent_with_an_alternate_number_is_eligible_on_that_number(): void
    {
        $customer = $this->consenting(['whatsapp_phone' => '+2348099999999']);

        $this->assertTrue(WhatsAppAutomationEligibility::permitsCustomer($customer));
        $this->assertSame('+2348099999999', $customer->effectiveWhatsAppPhone());
    }

    /** An un-canonical destination is refused, exactly as an un-canonical phone always was. */
    public function test_an_uncanonical_alternate_number_is_not_eligible(): void
    {
        $customer = $this->consenting(['whatsapp_phone' => '0809 123 4567']);

        $this->assertFalse(
            WhatsAppAutomationEligibility::permitsCustomer($customer),
            'a number that is not in canonical form must not be messaged'
        );
    }

    /** A customer with neither number has nowhere to send to. */
    public function test_a_customer_with_no_usable_number_has_no_destination(): void
    {
        $customer = Customer::factory()->create([
            'is_active' => true,
            'phone' => '',
            'whatsapp_phone' => null,
            'whatsapp_opt_in' => true,
            'whatsapp_opt_in_at' => now(),
        ]);

        $this->assertNull($customer->effectiveWhatsAppPhone());
        $this->assertFalse(WhatsAppAutomationEligibility::permitsCustomer($customer));
    }

    /** Opting out later revokes contactability without disturbing the destination. */
    public function test_opting_out_revokes_eligibility_but_leaves_the_number(): void
    {
        $customer = $this->consenting([
            'whatsapp_phone' => '+2348099999999',
            'whatsapp_opt_in' => false,
            'whatsapp_opt_out_at' => now(),
        ]);

        $this->assertFalse(WhatsAppAutomationEligibility::permitsCustomer($customer));
        $this->assertSame('+2348099999999', $customer->effectiveWhatsAppPhone());
    }
}
