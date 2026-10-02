<?php

namespace Tests\Feature\BusinessSettings;

use App\Enums\UserRole;
use App\Models\BusinessSetting;
use App\Models\User;
use App\Models\WhatsAppAutomation;
use App\Models\WhatsAppConnection;
use App\Support\ImageStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The Business profile screen: the new profile fields, the logo, and the WhatsApp card.
 *
 * Three promises this file exists to keep, each one a place where the Figma and the system differ:
 *
 *  - Currency is an allowlist, not free text. Every money view renders a hard-coded naira symbol,
 *    so storing "USD" would make the screen claim a multi-currency system that does not exist.
 *  - The WhatsApp card reports Meta's own resolved state and never a secret. A token reaching the
 *    HTML would hand whoever can read the page the ability to send as the business.
 *  - Disconnect is unavailable rather than local-only. Deleting the row here while Meta still holds
 *    the webhook subscription would leave the connection live at the provider and invisible here.
 */
class BusinessProfileScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame(
            'inventra_test',
            DB::connection()->getDatabaseName(),
            'Refusing to run: these tests write, and this is not the test database.'
        );

        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
    }

    private function page(): string
    {
        return $this->actingAs($this->admin)->get(route('settings.business.edit'))->assertOk()->getContent();
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'business_name' => 'Inventra Smart Trade', 'business_phone' => null,
            'business_address' => null, 'receipt_footer' => null, 'currency' => 'NGN',
        ], $overrides);
    }

    // ── Schema ──────────────────────────────────────────────────────────────────────────────────

    /** The migration's columns exist with the semantics the profile relies on. */
    public function test_the_new_profile_columns_exist_with_their_intended_defaults(): void
    {
        $row = DB::table('business_settings')->firstOrFail();

        foreach (['logo_path', 'business_type', 'currency', 'tax_number'] as $column) {
            $this->assertObjectHasProperty($column, $row);
        }

        // The existing installation keeps working: currency defaults rather than arriving null.
        $this->assertSame('NGN', $row->currency);
        // Everything else is optional and starts empty rather than inventing a value.
        $this->assertNull($row->logo_path);
        $this->assertNull($row->business_type);
        $this->assertNull($row->tax_number);
    }

    /** The record that existed before the migration is still the one and only record. */
    public function test_the_existing_settings_record_survived_the_migration(): void
    {
        $this->assertSame(1, DB::table('business_settings')->count());
        $this->assertNotSame('', trim((string) DB::table('business_settings')->value('business_name')));
    }

    // ── Persistence ─────────────────────────────────────────────────────────────────────────────

    public function test_the_new_fields_persist_and_reload(): void
    {
        $this->actingAs($this->admin)->put(route('settings.business.update'), $this->payload([
            'business_type' => 'Electronics & phones',
            'tax_number' => '0123456-0001',
        ]))->assertRedirect(route('settings.business.edit'));

        $row = DB::table('business_settings')->firstOrFail();
        $this->assertSame('Electronics & phones', $row->business_type);
        $this->assertSame('0123456-0001', $row->tax_number);
        $this->assertSame('NGN', $row->currency);

        $html = $this->page();
        $this->assertStringContainsString('0123456-0001', $html);
    }

    /** A cleared optional field records an explicit null rather than an empty string. */
    public function test_clearing_the_tax_number_stores_null(): void
    {
        $this->actingAs($this->admin)->put(route('settings.business.update'),
            $this->payload(['tax_number' => '0123456-0001']))->assertRedirect();

        $this->actingAs($this->admin)->put(route('settings.business.update'),
            $this->payload(['tax_number' => '  ']))->assertRedirect();

        $this->assertNull(DB::table('business_settings')->value('tax_number'));
    }

    // ── Currency ────────────────────────────────────────────────────────────────────────────────

    public function test_the_supported_currency_is_accepted(): void
    {
        $this->actingAs($this->admin)->put(route('settings.business.update'), $this->payload(['currency' => 'NGN']))
            ->assertSessionHasNoErrors();

        $this->assertSame('NGN', DB::table('business_settings')->value('currency'));
    }

    /**
     * An unsupported code is refused.
     *
     * Accepting it would let the profile claim a currency that every money view in Inventra would
     * then contradict by rendering a naira sign beside it.
     */
    public function test_an_unsupported_currency_code_is_refused(): void
    {
        foreach (['USD', 'GHS', 'ngn', 'NG', 'NGNN', '', '<script>', '123'] as $code) {
            $this->actingAs($this->admin)
                ->put(route('settings.business.update'), $this->payload(['currency' => $code]))
                ->assertSessionHasErrors('currency', "currency={$code} must be refused");
        }

        // Nothing got through.
        $this->assertSame('NGN', DB::table('business_settings')->value('currency'));
    }

    /** The selector offers only what the system can honour. */
    public function test_the_currency_selector_offers_only_the_supported_currency(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('Nigerian Naira (NGN)', $html);
        $this->assertStringNotContainsString('US Dollar', $html);
        $this->assertSame(['NGN'], array_keys(BusinessSetting::CURRENCIES));
    }

    // ── Business type ───────────────────────────────────────────────────────────────────────────

    public function test_an_unlisted_business_type_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->put(route('settings.business.update'), $this->payload(['business_type' => '<script>alert(1)</script>']))
            ->assertSessionHasErrors('business_type');

        $this->assertNull(DB::table('business_settings')->value('business_type'));
    }

    public function test_business_type_is_a_controlled_select_with_an_other_fallback(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('name="business_type"', $html);
        $this->assertStringContainsString('<select', $html);
        $this->assertContains('Other', BusinessSetting::TYPES);
    }

    // ── City and state ──────────────────────────────────────────────────────────────────────────

    // ── Logo ────────────────────────────────────────────────────────────────────────────────────

    public function test_an_administrator_can_upload_a_logo_and_it_is_stored_as_a_path(): void
    {
        $this->actingAs($this->admin)->post(route('settings.business.logo.store'), [
            'logo' => UploadedFile::fake()->image('logo.png', 300, 300),
        ])->assertRedirect();

        $path = DB::table('business_settings')->value('logo_path');

        $this->assertNotNull($path);
        // A path into the private image directory, never image bytes in the column.
        $this->assertStringStartsWith(ImageStore::BUSINESS.'/', $path);
        $this->assertLessThan(255, strlen($path));
        $this->assertTrue(app(ImageStore::class)->exists($path));
    }

    /** Replacing a logo removes the file it replaced rather than orphaning it. */
    public function test_replacing_the_logo_deletes_the_previous_file(): void
    {
        $images = app(ImageStore::class);

        $this->actingAs($this->admin)->post(route('settings.business.logo.store'),
            ['logo' => UploadedFile::fake()->image('first.png', 200, 200)])->assertRedirect();
        $first = DB::table('business_settings')->value('logo_path');

        $this->actingAs($this->admin)->post(route('settings.business.logo.store'),
            ['logo' => UploadedFile::fake()->image('second.png', 200, 200)])->assertRedirect();
        $second = DB::table('business_settings')->value('logo_path');

        $this->assertNotSame($first, $second);
        $this->assertTrue($images->exists($second));
        $this->assertFalse($images->exists($first), 'the replaced logo must not linger on disk');
    }

    /**
     * A script wearing an image extension is refused.
     *
     * The rules decode the file rather than trusting its name, which is the whole point: an
     * executable stored under the web root is the classic upload compromise.
     */
    public function test_a_disguised_executable_upload_is_rejected(): void
    {
        $this->actingAs($this->admin)->post(route('settings.business.logo.store'), [
            'logo' => UploadedFile::fake()->createWithContent('logo.png', '<?php echo "pwned"; ?>'),
        ])->assertSessionHasErrors('logo');

        $this->assertNull(DB::table('business_settings')->value('logo_path'));
    }

    public function test_an_oversized_upload_is_rejected(): void
    {
        $this->actingAs($this->admin)->post(route('settings.business.logo.store'), [
            'logo' => UploadedFile::fake()->image('big.jpg', 500, 500)->size(3072),
        ])->assertSessionHasErrors('logo');

        $this->assertNull(DB::table('business_settings')->value('logo_path'));
    }

    /** Only an Administrator may set the logo. */
    public function test_a_non_administrator_cannot_upload_a_logo(): void
    {
        foreach ([UserRole::Manager, UserRole::SalesRep] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user)->post(route('settings.business.logo.store'),
                ['logo' => UploadedFile::fake()->image('logo.png', 200, 200)])->assertForbidden();
        }

        $this->assertNull(DB::table('business_settings')->value('logo_path'));
    }

    /** `logo_path` cannot be steered by the settings form — only the upload action writes it. */
    public function test_a_posted_logo_path_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->put(route('settings.business.update'), $this->payload(['logo_path' => '../../.env']))
            ->assertSessionHasErrors('logo_path');

        $this->assertNull(DB::table('business_settings')->value('logo_path'));
    }

    /** With no logo, the identity block falls back to initials rather than a broken image. */
    public function test_the_identity_block_falls_back_to_initials(): void
    {
        $this->actingAs($this->admin)->put(route('settings.business.update'),
            $this->payload(['business_name' => 'Adaeze Stores']))->assertRedirect();

        $html = $this->page();

        $this->assertStringContainsString('AS', $html);
        $this->assertStringNotContainsString('<img src="'.route('settings.business.logo').'"', $html);
    }

    /**
     * The logo caption claims only what is true.
     *
     * Nothing consumes `logo_path` yet — no receipt or WhatsApp template was changed — so the
     * Figma's "appears on receipts & WhatsApp messages" would be a promise the system does not keep.
     */
    public function test_the_logo_caption_makes_no_false_claim(): void
    {
        $html = $this->page();

        $this->assertStringNotContainsString('appears on receipts', $html);
        $this->assertStringContainsString('Business logo', $html);
    }

    // ── WhatsApp card ───────────────────────────────────────────────────────────────────────────

    public function test_the_card_is_truthful_when_no_number_is_connected(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('No business number connected', $html);
        $this->assertStringContainsString('Connect business number', $html);
        $this->assertStringContainsString(route('whatsapp.automation.index'), $html);
        $this->assertStringNotContainsString('Business WhatsApp · verified', $html);
    }

    /** Connected state reports Meta's own resolved number. */
    public function test_a_connected_card_shows_metas_display_number(): void
    {
        $this->connect();

        $html = $this->page();

        $this->assertStringContainsString('+2348039998877', $html);
        $this->assertStringContainsString('Business WhatsApp · verified', $html);
        $this->assertStringContainsString('Connected', $html);
    }

    /**
     * No secret may reach the page.
     *
     * The token is what sends as the business; a page that carried it would hand that ability to
     * anyone who could read the HTML — a shoulder, a screenshot, a cached response.
     */
    public function test_no_whatsapp_secret_reaches_the_rendered_page(): void
    {
        $this->connect();

        $html = $this->page();

        foreach (['SUPERSECRETTOKEN', 'APPSECRET123', 'VERIFYTOKEN123', 'access_token', 'app_secret'] as $secret) {
            $this->assertStringNotContainsString($secret, $html, "a secret reached the page: {$secret}");
        }
    }

    /** Disconnect is rendered unavailable and explains itself, rather than faking the capability. */
    public function test_disconnect_is_unavailable_and_says_why(): void
    {
        $this->connect();

        $html = $this->page();

        $this->assertStringContainsString('disabled', $html);
        $this->assertStringContainsString(
            'Disconnect will be available after Meta connection management is enabled.',
            $html
        );
        // And there is no local disconnect endpoint to reach, by any path.
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('settings.business.whatsapp.disconnect'));
    }

    /** Connected state is never inferred from configuration. */
    public function test_connected_state_is_not_derived_from_the_environment(): void
    {
        config(['whatsapp.access_token' => 'SUPERSECRETTOKEN', 'whatsapp.phone_number_id' => '999']);

        $html = $this->page();

        // No row, so no connection — whatever the environment says.
        $this->assertStringContainsString('No business number connected', $html);
    }

    // ── Removed fields ──────────────────────────────────────────────────────────────────────────

    /**
     * Email, city and state left the screen.
     *
     * Removed from the UI, NOT from the database — which is why the prohibition below matters as
     * much as the absence: an unlisted key would be ignored silently, letting a stale cached form
     * quietly blank data no screen is responsible for any more.
     */
    public function test_email_city_and_state_do_not_render(): void
    {
        $html = $this->page();

        foreach (['business_email', 'city', 'state'] as $field) {
            $this->assertStringNotContainsString('name="'.$field.'"', $html, "{$field} must not render");
            $this->assertStringNotContainsString('id="'.$field.'"', $html);
        }
        $this->assertStringNotContainsString('>Email<', $html);
        $this->assertStringNotContainsString('>City<', $html);
        $this->assertStringNotContainsString('>State<', $html);
    }

    public function test_submitting_a_removed_field_is_refused_rather_than_ignored(): void
    {
        foreach (BusinessSetting::PRESERVED_NOT_EDITABLE as $field) {
            $this->actingAs($this->admin)
                ->put(route('settings.business.update'), $this->payload([$field => 'injected']))
                ->assertSessionHasErrors($field, "{$field} must be refused outright");
        }
    }

    /**
     * An ordinary save leaves the hidden columns byte-for-byte unchanged.
     *
     * This is the regression the removal was most likely to cause: a form that no longer submits a
     * field, with the field still on the editable list, sends it as absent and clears it.
     */
    public function test_saving_the_profile_preserves_hidden_email_city_and_state(): void
    {
        DB::table('business_settings')->update([
            'business_email' => 'existing@example.com', 'city' => 'Lagos', 'state' => 'Lagos',
        ]);
        app(\App\Settings\BusinessSettings::class)->forget();

        $this->actingAs($this->admin)->put(route('settings.business.update'), $this->payload([
            'business_name' => 'Akin Auto Parts',
            'business_phone' => '08025550190',
            'business_address' => '14 Ladipo Street, Mushin, Lagos',
            'receipt_footer' => 'Thank you for your patronage!',
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $row = DB::table('business_settings')->firstOrFail();
        $this->assertSame('existing@example.com', $row->business_email);
        $this->assertSame('Lagos', $row->city);
        $this->assertSame('Lagos', $row->state);
        // And the visible edit really did apply, so this is not passing by doing nothing.
        $this->assertSame('Akin Auto Parts', $row->business_name);
    }

    /** The hidden columns still exist; nothing dropped them. */
    public function test_the_hidden_columns_still_exist(): void
    {
        foreach (BusinessSetting::PRESERVED_NOT_EDITABLE as $column) {
            $this->assertTrue(Schema::hasColumn('business_settings', $column), "{$column} must still exist");
        }
    }

    // ── Manager alert number ────────────────────────────────────────────────────────────────────

    public function test_the_manager_alert_number_renders_with_its_helper_text(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('Manager alert number', $html);
        $this->assertStringContainsString('name="manager_alert_number"', $html);
        $this->assertStringContainsString('Full automation lives under WhatsApp Automation in the sidebar.', $html);
    }

    /** The recipients UI is gone in every form of words it used. */
    public function test_the_low_stock_recipient_section_does_not_render(): void
    {
        $html = $this->page();

        $this->assertStringNotContainsString('Low-stock alert recipients', $html);
        $this->assertStringNotContainsString('No recipients selected', $html);
        $this->assertStringNotContainsString('Manage automation', $html);
        $this->assertStringNotContainsString('staff member', $html);
    }

    /** @return array<string, array{string}> */
    public static function friendlyAlertNumbers(): array
    {
        return [
            'spaced local' => ['0801 234 5678'],
            'bare local' => ['08012345678'],
            'spaced international' => ['+234 801 234 5678'],
            'country code without plus' => ['2348012345678'],
        ];
    }

    #[DataProvider('friendlyAlertNumbers')]
    public function test_a_friendly_alert_number_is_stored_canonically(string $input): void
    {
        $this->actingAs($this->admin)
            ->put(route('settings.business.update'), $this->payload(['manager_alert_number' => $input]))
            ->assertSessionHasNoErrors();

        $this->assertSame('+2348012345678', DB::table('business_settings')->value('manager_alert_number'),
            "{$input} must canonicalise");
    }

    public function test_a_malformed_alert_number_is_refused(): void
    {
        foreach (['12345', '080123456', '0601234567', 'not a phone', '+1 415 555 0190'] as $bad) {
            $this->actingAs($this->admin)
                ->put(route('settings.business.update'), $this->payload(['manager_alert_number' => $bad]))
                ->assertSessionHasErrors(['manager_alert_number' => 'Enter a valid Nigerian phone number.']);
        }

        $this->assertNull(DB::table('business_settings')->value('manager_alert_number'));
    }

    public function test_a_blank_alert_number_stores_null(): void
    {
        $this->actingAs($this->admin)->put(route('settings.business.update'),
            $this->payload(['manager_alert_number' => '08012345678']))->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->put(route('settings.business.update'),
            $this->payload(['manager_alert_number' => '   ']))->assertSessionHasNoErrors();

        $this->assertNull(DB::table('business_settings')->value('manager_alert_number'));
    }

    /**
     * This is a business setting, not a personal one.
     *
     * The word "manager" in the field name grants nobody access: the existing BusinessSetting
     * policy decides, and it admits Administrators only.
     */
    public function test_a_manager_or_sales_rep_cannot_change_the_alert_number(): void
    {
        foreach ([UserRole::Manager, UserRole::SalesRep] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user)
                ->put(route('settings.business.update'), $this->payload(['manager_alert_number' => '08012345678']))
                ->assertForbidden();
        }

        $this->assertNull(DB::table('business_settings')->value('manager_alert_number'));
    }

    // ── Actions ─────────────────────────────────────────────────────────────────────────────────

    public function test_the_footer_offers_cancel_and_save(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('Save changes', $html);
        $this->assertStringContainsString('Cancel', $html);
    }

    /** A rejected submission keeps what was typed and says which field was wrong. */
    public function test_a_validation_failure_preserves_input_and_reports_the_field(): void
    {
        $this->actingAs($this->admin)
            ->put(route('settings.business.update'), $this->payload([
                'business_name' => '', 'tax_number' => 'KEEP-THIS-0001',
            ]))
            ->assertSessionHasErrors('business_name')
            ->assertSessionHasInput('tax_number', 'KEEP-THIS-0001');
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────────────────

    private function connect(): WhatsAppConnection
    {
        $connection = WhatsAppConnection::forCurrentBusiness();
        $connection->forceFill([
            'status' => 'connected',
            'waba_id' => '1234567890',
            'phone_number_id' => '9876543210',
            'display_phone_number' => '+234 803 999 8877',
            'access_token' => 'SUPERSECRETTOKEN',
            'verified_at' => now(),
            'connected_at' => now(),
        ])->save();

        return $connection;
    }

    private function lowStockAutomation(): WhatsAppAutomation
    {
        return WhatsAppAutomation::forKey(WhatsAppAutomation::LOW_STOCK)
            ?? WhatsAppAutomation::query()->create(['key' => WhatsAppAutomation::LOW_STOCK]);
    }
}
