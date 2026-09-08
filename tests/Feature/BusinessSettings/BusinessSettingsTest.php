<?php

namespace Tests\Feature\BusinessSettings;

use App\Actions\Settings\UpdateBusinessSettings;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\BusinessSetting;
use App\Models\Sale;
use App\Models\User;
use App\Services\AuditLogger;
use App\Settings\BusinessSettings;
use App\Support\WhatsAppReceiptTemplate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class BusinessSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const FINGERPRINTED = ['business_settings', 'audit_logs', 'security_events', 'users'];

    /* ------------------------------------------------------------ singleton & bootstrap */

    public function test_migration_bootstraps_exactly_one_settings_record(): void
    {
        $this->assertSame(1, DB::table('business_settings')->count());
        $row = DB::table('business_settings')->firstOrFail();
        $this->assertSame(BusinessSetting::SINGLETON_KEY, $row->singleton_key);
        $this->assertNotSame('', trim($row->business_name));
        $this->assertNull($row->updated_by);
    }

    public function test_the_database_refuses_a_second_settings_record_by_any_path(): void
    {
        $existing = DB::table('business_settings')->firstOrFail();

        $attempts = [
            'duplicate singleton key' => fn () => DB::table('business_settings')->insert([
                'singleton_key' => BusinessSetting::SINGLETON_KEY, 'business_name' => 'Second',
                'created_at' => now(), 'updated_at' => now()]),
            'different singleton key' => fn () => DB::table('business_settings')->insert([
                'singleton_key' => 'other', 'business_name' => 'Second',
                'created_at' => now(), 'updated_at' => now()]),
            'null singleton key' => fn () => DB::table('business_settings')->insert([
                'singleton_key' => null, 'business_name' => 'Second',
                'created_at' => now(), 'updated_at' => now()]),
            'blank business name' => fn () => DB::table('business_settings')
                ->where('id', $existing->id)->update(['business_name' => '   ']),
        ];

        foreach ($attempts as $label => $attempt) {
            try {
                $attempt();
                $this->fail("The database must reject: {$label}");
            } catch (Throwable) {
                // expected
            }
        }

        $this->assertSame(1, DB::table('business_settings')->count());
        $this->assertEquals($existing, DB::table('business_settings')->firstOrFail());
    }

    public function test_concurrent_creation_attempts_cannot_produce_a_second_record(): void
    {
        foreach (range(1, 5) as $attempt) {
            try {
                BusinessSetting::query()->insert([
                    'singleton_key' => BusinessSetting::SINGLETON_KEY,
                    'business_name' => "Racer {$attempt}", 'created_at' => now(), 'updated_at' => now()]);
            } catch (Throwable) {
                // expected
            }
        }

        $this->assertSame(1, DB::table('business_settings')->count());
    }

    /* --------------------------------------------------------------- read architecture */

    public function test_reading_settings_never_creates_a_record_and_fails_clearly_when_absent(): void
    {
        $admin = $this->admin();
        DB::table('business_settings')->delete();

        try {
            app(BusinessSettings::class)->current();
            $this->fail('A missing settings record must fail explicitly.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('not installed', $exception->getMessage());
            $this->assertStringContainsString('migrate', $exception->getMessage());
        }

        $this->assertSame(0, DB::table('business_settings')->count(),
            'Reading must never create the record it could not find');

        // The page fails controlled rather than with "call to a member function on null".
        $status = 0;
        try {
            $status = $this->actingAs($admin)->get(route('settings.business.edit'))->getStatusCode();
        } catch (Throwable $exception) {
            $this->assertInstanceOf(RuntimeException::class, $exception);
            $status = 500;
        }
        $this->assertSame(500, $status);
        $this->assertSame(0, DB::table('business_settings')->count());
    }

    public function test_repeated_reads_in_one_request_cost_a_single_query(): void
    {
        $admin = $this->admin();
        $settings = app(BusinessSettings::class);

        $count = 0;
        DB::listen(function ($query) use (&$count): void {
            if (str_contains($query->sql, 'business_settings')) {
                $count++;
            }
        });
        foreach (range(1, 10) as $ignored) {
            $settings->current();
        }
        DB::getEventDispatcher()->forget(QueryExecuted::class);

        $this->assertSame(1, $count, 'The singleton read must be memoised for the request');
    }

    /* ------------------------------------------------------------------ authorization */

    public function test_only_administrators_may_view_or_update_business_settings(): void
    {
        $payload = $this->payload();

        $this->get(route('settings.business.edit'))->assertRedirect(route('login'));
        $this->put(route('settings.business.update'), $payload)->assertRedirect(route('login'));

        foreach ([UserRole::Manager, UserRole::SalesRep] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user)->get(route('settings.business.edit'))->assertForbidden();
            $this->actingAs($user)->put(route('settings.business.update'), $payload)->assertForbidden();
        }

        $admin = $this->admin();
        $this->actingAs($admin)->get(route('settings.business.edit'))->assertOk()->assertSee('Business settings');
        $this->actingAs($admin)->put(route('settings.business.update'), $payload)
            ->assertRedirect(route('settings.business.edit'));

        $this->assertSame($payload['business_name'], DB::table('business_settings')->value('business_name'));
    }

    public function test_navigation_offers_business_settings_to_administrators_only(): void
    {
        $this->assertStringContainsString('Business Settings',
            $this->actingAs($this->admin())->get(route('dashboard'))->getContent());

        foreach ([UserRole::Manager, UserRole::SalesRep] as $role) {
            $this->assertStringNotContainsString('Business Settings',
                $this->actingAs(User::factory()->create(['role' => $role]))->get(route('dashboard'))->getContent());
        }
    }

    /* ------------------------------------------- retired field: registered legal name */

    public function test_the_settings_page_offers_business_name_as_the_only_business_name_field(): void
    {
        $html = $this->actingAs($this->admin())->get(route('settings.business.edit'))->assertOk()->getContent();

        // Gone from the UI, not merely hidden or disabled.
        $this->assertStringNotContainsStringIgnoringCase('Registered legal name', $html);
        $this->assertStringNotContainsStringIgnoringCase('Legal name', $html);
        $this->assertStringNotContainsString('legal_name', $html, 'No legal_name input may be rendered');

        // The retained field is still there, still required, and is the only business-name input.
        $this->assertStringContainsString('name="business_name"', $html);
        $this->assertSame(1, substr_count($html, 'name="business_name"'));
        $this->assertMatchesRegularExpression('/name="business_name"[^>]*required|required[^>]*name="business_name"/', $html);

        // Nothing was left behind as an empty column or a placeholder control in the Identity card.
        $identity = (string) preg_replace('/.*<h3[^>]*>Identity<\/h3>(.*?)<div class="rounded-2xl.*/s', '$1', $html);
        $this->assertSame(1, substr_count($identity, '<input'), 'Identity holds exactly one control');
        $this->assertStringNotContainsString('disabled', $identity);
        $this->assertStringNotContainsString('hidden', $identity);
    }

    public function test_the_form_submits_without_a_legal_name_and_persists_every_retained_setting(): void
    {
        $admin = $this->admin();
        $before = DB::table('business_settings')->firstOrFail();

        $payload = [
            'business_name' => 'Adaeze Stores', 'business_phone' => '08031234567',
            'business_email' => 'hello@adaeze.example', 'business_address' => '12 Market Road',
            'city' => 'Onitsha', 'state' => 'Anambra', 'receipt_footer' => 'Thank you for your business.',
        ];
        $this->assertArrayNotHasKey('legal_name', $payload, 'The browser no longer submits this field');

        $this->actingAs($admin)->put(route('settings.business.update'), $payload)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('settings.business.edit'))
            ->assertSessionHas('status', 'Business settings updated.');

        $after = DB::table('business_settings')->firstOrFail();
        $this->assertSame('Adaeze Stores', $after->business_name);
        $this->assertSame('+2348031234567', $after->business_phone);
        $this->assertSame('hello@adaeze.example', $after->business_email);
        $this->assertSame('12 Market Road', $after->business_address);
        $this->assertSame('Onitsha', $after->city);
        $this->assertSame('Anambra', $after->state);
        $this->assertSame('Thank you for your business.', $after->receipt_footer);
        $this->assertSame($admin->id, $after->updated_by);
        $this->assertSame(1, DB::table('business_settings')->count());

        // The audit event still fires and still diffs every retained field.
        $log = $this->latestSettingsAudit();
        $this->assertSame('business_settings_updated', $log->action);
        $this->assertSame($admin->name, $log->actor_name_snapshot);
        $this->assertSame(UserRole::Admin->value, $log->actor_role_snapshot);
        foreach (['business_name', 'business_phone', 'business_email', 'business_address', 'city', 'state', 'receipt_footer'] as $field) {
            $this->assertArrayHasKey($field, $log->new_values, "{$field} must still be audited");
        }
        $this->assertSame($before->business_name, $log->old_values['business_name']);
        $this->assertSame('Adaeze Stores', $log->new_values['business_name']);
        $this->assertArrayNotHasKey('legal_name', $log->old_values);
        $this->assertArrayNotHasKey('legal_name', $log->new_values);

        // Reloading shows the saved values.
        $reloaded = $this->actingAs($admin)->get(route('settings.business.edit'))->assertOk()->getContent();
        foreach (['Adaeze Stores', '+2348031234567', 'hello@adaeze.example', '12 Market Road', 'Onitsha', 'Anambra'] as $value) {
            $this->assertStringContainsString($value, $reloaded, "{$value} must survive a reload");
        }
    }

    public function test_a_submitted_legal_name_is_ignored_rather_than_written_or_audited(): void
    {
        $admin = $this->admin();
        $legacy = DB::table('business_settings')->value('legal_name');
        $audit = DB::table('audit_logs')->count();

        // A stale cached form or a hand-crafted request must not reach the retired column.
        $this->actingAs($admin)->put(route('settings.business.update'),
            $this->payload(['legal_name' => 'Injected Legal Ltd']))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame($legacy, DB::table('business_settings')->value('legal_name'),
            'The retired column must be unreachable from the request');
        $this->assertSame($audit, DB::table('audit_logs')->count(),
            'A retired field cannot manufacture an audit event');
        $this->assertNotContains('legal_name', BusinessSetting::EDITABLE);
    }

    public function test_receipts_keep_their_business_identity_after_the_legal_name_is_retired(): void
    {
        $admin = $this->admin();

        // Prove the letterhead cannot leak the retired column even when it still holds a value.
        DB::table('business_settings')->update(['legal_name' => 'Retired Legal Ltd']);
        app(BusinessSettings::class)->forget();

        $this->actingAs($admin)->put(route('settings.business.update'), $this->payload([
            'business_name' => 'Adaeze Stores', 'business_phone' => '08031234567',
            'business_address' => '12 Market Road', 'city' => 'Onitsha', 'state' => 'Anambra',
        ]))->assertSessionHasNoErrors();

        foreach ($this->receiptUrls($admin) as $label => $url) {
            $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('Adaeze Stores', $html,
                "{$label} must still carry the business identity");
            $this->assertStringContainsString('12 Market Road', $html, "{$label} keeps its address");
            $this->assertStringNotContainsString('Retired Legal Ltd', $html,
                "{$label} must not render the retired legal name");
        }
    }

    /* ------------------------------------------------------------------ update & audit */

    public function test_a_valid_update_persists_and_records_only_the_changed_fields(): void
    {
        $admin = $this->admin();
        $before = DB::table('business_settings')->firstOrFail();

        $this->actingAs($admin)->put(route('settings.business.update'), $this->payload([
            'business_name' => 'Adaeze Stores',
            'business_phone' => '08031234567',
            'business_email' => 'hello@adaeze.example',
            'receipt_footer' => "Thank you for your business.\nGoods sold in good condition.",
        ]))->assertRedirect()->assertSessionHas('status', 'Business settings updated.');

        $after = DB::table('business_settings')->firstOrFail();
        $this->assertSame('Adaeze Stores', $after->business_name);
        $this->assertSame('+2348031234567', $after->business_phone, 'Nigerian phones are canonicalised');
        $this->assertSame($admin->id, $after->updated_by);
        $this->assertSame(1, DB::table('business_settings')->count());

        $event = AuditLog::query()->where('action', 'business_settings_updated')->latest('id')->firstOrFail();
        $this->assertSame($admin->name, $event->actor_name_snapshot);
        $this->assertSame(UserRole::Admin->value, $event->actor_role_snapshot);
        $this->assertSame($before->business_name, $event->old_values['business_name']);
        $this->assertSame('Adaeze Stores', $event->new_values['business_name']);
        $this->assertArrayNotHasKey('city', $event->new_values, 'Unchanged fields are not audited as changes');
    }

    public function test_the_audit_subject_is_stable_and_does_not_follow_the_business_name(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->put(route('settings.business.update'), $this->payload(['business_name' => 'First Name']));
        $first = AuditLog::query()->where('action', 'business_settings_updated')->latest('id')->firstOrFail();

        $this->actingAs($admin)->put(route('settings.business.update'), $this->payload(['business_name' => 'Second Name']));
        $second = AuditLog::query()->where('action', 'business_settings_updated')->latest('id')->firstOrFail();

        $this->assertSame($first->subject_label_snapshot, $second->subject_label_snapshot);
        $this->assertStringNotContainsStringIgnoringCase('First Name', (string) $second->subject_label_snapshot);
        $this->assertStringNotContainsStringIgnoringCase('Second Name', (string) $second->subject_label_snapshot);
    }

    public function test_submitting_the_current_values_changes_nothing_and_records_no_audit_event(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->put(route('settings.business.update'), $this->payload(['business_name' => 'Steady State']));

        $row = DB::table('business_settings')->firstOrFail();
        $audit = DB::table('audit_logs')->count();

        $this->actingAs($admin)->put(route('settings.business.update'), $this->payload(['business_name' => 'Steady State']))
            ->assertRedirect()->assertSessionHas('status', 'No changes were made to the business settings.');

        $this->assertEquals($row, DB::table('business_settings')->firstOrFail(), 'A no-op must not touch the row');
        $this->assertSame($audit, DB::table('audit_logs')->count(), 'A no-op must not record an audit event');
    }

    public function test_a_failing_audit_write_rolls_back_the_settings_change(): void
    {
        $admin = $this->admin();
        $before = DB::table('business_settings')->firstOrFail();
        $auditRows = DB::table('audit_logs')->count();

        $this->app->bind(AuditLogger::class, fn () => new class extends AuditLogger
        {
            public function record(string $action, Model $auditable, ?User $actor,
                array $oldValues = [], array $newValues = [], array $metadata = [],
                bool $explicitDiff = false): void
            {
                throw new RuntimeException('audit unavailable');
            }
        });

        try {
            app(UpdateBusinessSettings::class)->execute($admin, ['business_name' => 'Should Not Persist']);
            $this->fail('The settings change must not commit without its audit evidence.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertEquals($before, DB::table('business_settings')->firstOrFail());
        $this->assertSame($auditRows, DB::table('audit_logs')->count(), 'No orphan audit row may survive');
    }

    public function test_an_invalid_submission_writes_nothing_and_records_nothing(): void
    {
        $admin = $this->admin();
        $before = DB::table('business_settings')->firstOrFail();
        $auditRows = DB::table('audit_logs')->count();

        $this->actingAs($admin)->put(route('settings.business.update'),
            $this->payload(['business_name' => '', 'business_email' => 'not-an-email']))
            ->assertSessionHasErrors(['business_name', 'business_email']);

        $this->assertEquals($before, DB::table('business_settings')->firstOrFail());
        $this->assertSame($auditRows, DB::table('audit_logs')->count());
    }

    public function test_a_second_administrator_saving_over_the_first_keeps_one_row_and_audits_what_changed(): void
    {
        $first = $this->admin('First Admin');
        $second = User::factory()->create(['role' => UserRole::Admin, 'name' => 'Second Admin']);

        // Both loaded the same page; both submit different values for the same field.
        $this->actingAs($first)->put(route('settings.business.update'), $this->payload(['business_name' => 'From First']));
        $this->actingAs($second)->put(route('settings.business.update'), $this->payload(['business_name' => 'From Second']));

        $this->assertSame(1, DB::table('business_settings')->count());
        $this->assertSame('From Second', DB::table('business_settings')->value('business_name'),
            'Last writer wins, which is the documented behaviour');

        $events = AuditLog::query()->where('action', 'business_settings_updated')->orderBy('id')->get();
        $this->assertCount(2, $events);
        $this->assertSame('From First', $events[0]->new_values['business_name']);
        $this->assertSame('From First', $events[1]->old_values['business_name'],
            'The second writer audits the value it actually replaced, read under the row lock');
        $this->assertSame('From Second', $events[1]->new_values['business_name']);
    }

    /* --------------------------------------------------- mass assignment & secrets */

    public function test_protected_and_secret_shaped_fields_can_never_be_written(): void
    {
        $admin = $this->admin();
        $before = DB::table('business_settings')->firstOrFail();

        $prohibited = ['id' => 999, 'singleton_key' => 'hijacked', 'created_at' => '2000-01-01 00:00:00',
            'updated_at' => '2000-01-01 00:00:00', 'updated_by' => 999,
            'currency' => 'USD', 'currency_code' => 'USD', 'currency_symbol' => '$', 'timezone' => 'America/New_York'];

        foreach ($prohibited as $field => $value) {
            $this->actingAs($admin)->put(route('settings.business.update'), $this->payload([$field => $value]))
                ->assertSessionHasErrors($field, "Submitting {$field} must be rejected outright");
        }

        $secrets = ['password' => 'hunter2', 'api_key' => 'APIKEY', 'webhook_secret' => 'WEBHOOK',
            'meta_access_token' => 'METATOKEN', 'app_secret' => 'APPSECRET', 'smtp_password' => 'SMTPPASS',
            'encryption_key' => 'ENCKEY', 'pin' => '1234', 'arbitrary_field' => 'ARBITRARY'];

        $this->actingAs($admin)->put(route('settings.business.update'), $this->payload($secrets))->assertRedirect();

        $columns = array_keys((array) DB::table('business_settings')->firstOrFail());
        foreach (array_keys($secrets) as $field) {
            $this->assertNotContains($field, $columns, "business_settings must have no {$field} column");
        }

        $stored = mb_strtolower((string) json_encode(DB::table('business_settings')->get()));
        foreach (['hunter2', 'apikey', 'webhook', 'metatoken', 'appsecret', 'smtppass', 'enckey', 'arbitrary'] as $needle) {
            $this->assertStringNotContainsString($needle, $stored, "A secret-shaped value reached storage: {$needle}");
        }

        $after = DB::table('business_settings')->firstOrFail();
        $this->assertSame($before->id, $after->id);
        $this->assertSame(BusinessSetting::SINGLETON_KEY, $after->singleton_key);
        $this->assertSame($before->created_at, $after->created_at);
    }

    /* ------------------------------------------------------------------- validation */

    public function test_malformed_and_hostile_input_never_breaks_the_settings_endpoint(): void
    {
        $admin = $this->admin();
        $before = DB::table('business_settings')->firstOrFail();

        $probes = [
            'array business name' => ['business_name' => ['a']],
            'nested array' => ['business_name' => ['a' => ['b' => 'c']]],
            'array email' => ['business_email' => ['x@example.com']],
            'array phone' => ['business_phone' => ['0803']],
            'array address' => ['business_address' => ['x']],
            'array footer' => ['receipt_footer' => ['x']],
            'whitespace only name' => ['business_name' => '     '],
            'empty name' => ['business_name' => ''],
            'over-long name' => ['business_name' => str_repeat('a', 151)],
            'over-long footer' => ['receipt_footer' => str_repeat('a', 501)],
            'over-long email' => ['business_email' => str_repeat('a', 250).'@example.com'],
            'invalid email' => ['business_email' => 'not an email'],
            'invalid phone' => ['business_phone' => 'not-a-phone'],
            'sql payload' => ['business_name' => "'; DROP TABLE business_settings; --"],
            'null byte' => ['business_name' => "Shop\0Name"],
            'integer' => ['business_name' => 12345],
            'boolean' => ['business_name' => true],
        ];

        foreach ($probes as $label => $override) {
            $response = $this->actingAs($admin)->put(route('settings.business.update'), $this->payload($override));
            $this->assertContains($response->getStatusCode(), [302, 422],
                "Probe produced an unexpected status: {$label}");
        }

        $this->assertSame(1, DB::table('business_settings')->count());
        $this->assertSame($before->singleton_key, DB::table('business_settings')->value('singleton_key'));
    }

    public function test_boundary_lengths_and_unicode_are_accepted_and_stored_intact(): void
    {
        $admin = $this->admin();
        $name = str_repeat('a', 150);
        $footer = str_repeat('é', 500);
        $unicode = 'Adaeze Ọlá 🇳🇬 Stores';

        $this->actingAs($admin)->put(route('settings.business.update'),
            $this->payload(['business_name' => $name, 'receipt_footer' => $footer]))->assertSessionHasNoErrors();
        $this->assertSame($name, DB::table('business_settings')->value('business_name'));
        $this->assertSame($footer, DB::table('business_settings')->value('receipt_footer'));

        $this->actingAs($admin)->put(route('settings.business.update'),
            $this->payload(['business_name' => $unicode]))->assertSessionHasNoErrors();
        $this->assertSame($unicode, DB::table('business_settings')->value('business_name'));
        $this->assertStringContainsString('Ọlá',
            $this->actingAs($admin)->get(route('settings.business.edit'))->getContent());
    }

    public function test_leading_and_trailing_whitespace_is_trimmed_and_blank_optionals_become_null(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->put(route('settings.business.update'), $this->payload([
            'business_name' => '   Trimmed Stores   ', 'state' => '   ', 'city' => '  Lagos  ',
        ]))->assertSessionHasNoErrors();

        $row = DB::table('business_settings')->firstOrFail();
        $this->assertSame('Trimmed Stores', $row->business_name);
        $this->assertNull($row->state);
        $this->assertSame('Lagos', $row->city);
    }

    /* -------------------------------------------------------------------------- XSS */

    public function test_hostile_business_identity_is_escaped_on_settings_and_every_receipt(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->put(route('settings.business.update'), $this->payload([
            'business_name' => '<script>alert(1)</script>',
            'city' => '<img src=x onerror=alert(2)>',
            'business_address' => '"><svg onload=alert(3)>',
            'receipt_footer' => '<script>alert(4)</script>',
        ]))->assertSessionHasNoErrors();

        $urls = [route('settings.business.edit')];
        foreach ($this->receiptUrls($admin) as $url) {
            $urls[] = $url;
        }

        foreach ($urls as $url) {
            $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();
            foreach (['<script>alert(1)', '<img src=x onerror=', '"><svg onload=', '<script>alert(4)'] as $payload) {
                $this->assertStringNotContainsString($payload, $html, "Unescaped payload rendered at {$url}");
            }
            $this->assertStringContainsString('&lt;', $html);
        }
    }

    /* ------------------------------------------------------------- receipt integration */

    public function test_receipts_render_the_configured_business_identity_and_footer(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->put(route('settings.business.update'), $this->payload([
            'business_name' => 'Adaeze Stores',
            'business_phone' => '08031234567', 'business_email' => 'hello@adaeze.example',
            'business_address' => '12 Market Road', 'city' => 'Onitsha', 'state' => 'Anambra',
            'receipt_footer' => 'Thank you for your business.',
        ]))->assertSessionHasNoErrors();

        foreach ($this->receiptUrls($admin) as $label => $url) {
            $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('Adaeze Stores', $html, "{$label} must show the business name");
            $this->assertStringNotContainsString('Inventra Receipt', $html);
            $this->assertStringNotContainsString('Inventra Payment Receipt', $html);
            $this->assertStringNotContainsString('Inventra Stock Receiving Record', $html);

            $customerFacing = in_array($label, ['sale', 'payment', 'return', 'refund'], true);
            $this->assertSame($customerFacing, str_contains($html, 'Thank you for your business.'),
                "{$label} footer expectation mismatch");
        }
    }

    public function test_renaming_the_business_changes_historical_receipts_but_not_their_identifiers(): void
    {
        $admin = $this->admin();
        $urls = $this->receiptUrls($admin);
        $saleNumber = DB::table('sales')->value('sale_number');

        $this->actingAs($admin)->put(route('settings.business.update'), $this->payload(['business_name' => 'Original Name']));
        $this->assertStringContainsString('Original Name',
            $this->actingAs($admin)->get($urls['sale'])->getContent());

        $this->actingAs($admin)->put(route('settings.business.update'), $this->payload(['business_name' => 'Renamed Business']));
        $html = $this->actingAs($admin)->get($urls['sale'])->assertOk()->getContent();

        // Documented semantics: receipts render live business identity, not a transaction-time snapshot.
        $this->assertStringContainsString('Renamed Business', $html);
        $this->assertStringNotContainsString('Original Name', $html);

        // Identifiers and money are historical evidence and must be untouched by a rename.
        $this->assertStringContainsString($saleNumber, $html);
        $this->assertSame($saleNumber, DB::table('sales')->value('sale_number'));
        $this->assertSame(1, DB::table('sales')->count());
    }

    public function test_whatsapp_receipt_content_carries_no_business_identity(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->put(route('settings.business.update'), $this->payload([
            'business_name' => 'Adaeze Stores', 'receipt_footer' => 'Thank you for your business.',
        ]))->assertSessionHasNoErrors();

        $this->receiptUrls($admin);
        $sale = Sale::query()->latest('id')->firstOrFail();
        $parameters = WhatsAppReceiptTemplate::parameters($sale);

        // WhatsApp message bodies come from a provider-approved template filled with immutable Sale
        // snapshots. No business setting reaches them, so changing settings cannot alter historical
        // or re-rendered WhatsApp content.
        $encoded = (string) json_encode($parameters);
        $this->assertStringNotContainsString('Adaeze Stores', $encoded);
        $this->assertStringNotContainsString('Thank you for your business.', $encoded);
        $this->assertSame(['customer_name', 'sale_number', 'total_amount', 'payment_status', 'sold_by_name'],
            array_keys($parameters));
    }

    /* ------------------------------------------------------- clearing optional settings */

    public function test_clearing_the_receipt_footer_records_an_explicit_null(): void
    {
        $admin = $this->admin();
        $this->seedSettings($admin, ['receipt_footer' => 'Thank you']);

        $this->actingAs($admin)->put(route('settings.business.update'), $this->payload(['receipt_footer' => '']))
            ->assertRedirect()->assertSessionHas('status', 'Business settings updated.');

        $this->assertNull(DB::table('business_settings')->value('receipt_footer'));

        $log = $this->latestSettingsAudit();
        $this->assertSame(['receipt_footer' => 'Thank you'], $log->old_values);
        $this->assertIsArray($log->new_values, 'A cleared field must not collapse new_values to null');
        $this->assertArrayHasKey('receipt_footer', $log->new_values, 'The cleared key must be present');
        $this->assertNull($log->new_values['receipt_footer'], 'The cleared value must be exactly null');
    }

    public function test_clearing_the_state_records_an_explicit_null(): void
    {
        $admin = $this->admin();
        $this->seedSettings($admin, ['state' => 'Anambra']);

        $this->actingAs($admin)->put(route('settings.business.update'), $this->payload(['state' => '']))
            ->assertRedirect();

        $this->assertNull(DB::table('business_settings')->value('state'));

        $log = $this->latestSettingsAudit();
        $this->assertSame(['state' => 'Anambra'], $log->old_values);
        $this->assertSame(['state' => null], $log->new_values);
    }

    public function test_clearing_the_business_phone_records_an_explicit_null(): void
    {
        $admin = $this->admin();
        $this->seedSettings($admin, ['business_phone' => '+2348031234567']);

        $this->actingAs($admin)->put(route('settings.business.update'), $this->payload(['business_phone' => '']))
            ->assertRedirect();

        $this->assertNull(DB::table('business_settings')->value('business_phone'));

        $log = $this->latestSettingsAudit();
        $this->assertSame(['business_phone' => '+2348031234567'], $log->old_values);
        $this->assertSame(['business_phone' => null], $log->new_values);
    }

    public function test_clearing_several_fields_alongside_a_scalar_change_audits_both_faithfully(): void
    {
        $admin = $this->admin();
        $this->seedSettings($admin, [
            'state' => 'Anambra', 'city' => 'Lagos', 'business_email' => 'old@example.com',
            'business_address' => '12 Broad Street', 'receipt_footer' => 'Thank you',
        ]);

        $this->actingAs($admin)->put(route('settings.business.update'), $this->payload([
            'state' => '', 'city' => 'Ibadan', 'business_email' => '',
            'business_address' => '12 Broad Street', 'receipt_footer' => 'Thank you',
        ]))->assertRedirect();

        $log = $this->latestSettingsAudit();

        // MySQL normalises JSON object key order, so compare membership and values, not order.
        $this->assertEqualsCanonicalizing(['state', 'business_email', 'city'], array_keys($log->old_values));
        $this->assertEqualsCanonicalizing(['state', 'business_email', 'city'], array_keys($log->new_values));

        $this->assertSame('Anambra', $log->old_values['state']);
        $this->assertSame('old@example.com', $log->old_values['business_email']);
        $this->assertSame('Lagos', $log->old_values['city']);

        $this->assertNull($log->new_values['state'], 'A cleared field must be exactly null');
        $this->assertNull($log->new_values['business_email'], 'A cleared field must be exactly null');
        $this->assertSame('Ibadan', $log->new_values['city']);

        // Fields the Administrator left alone stay out of the record entirely.
        foreach (['business_address', 'receipt_footer', 'business_name', 'business_phone'] as $untouched) {
            $this->assertArrayNotHasKey($untouched, $log->old_values);
            $this->assertArrayNotHasKey($untouched, $log->new_values);
        }
    }

    public function test_resubmitting_an_already_blank_optional_field_stays_a_no_op(): void
    {
        $admin = $this->admin();
        $this->seedSettings($admin, ['state' => 'Anambra']);
        $this->actingAs($admin)->put(route('settings.business.update'), $this->payload(['state' => '']));

        $row = DB::table('business_settings')->firstOrFail();
        $audit = DB::table('audit_logs')->count();

        $this->actingAs($admin)->put(route('settings.business.update'), $this->payload(['state' => '']))
            ->assertRedirect()->assertSessionHas('status', 'No changes were made to the business settings.');

        $this->assertEquals($row, DB::table('business_settings')->firstOrFail());
        $this->assertSame($audit, DB::table('audit_logs')->count(),
            'Clearing an already-empty field must not manufacture an audit event');
    }

    public function test_only_keys_the_caller_supplies_can_become_null_in_an_audit_record(): void
    {
        $admin = $this->admin();
        $settings = BusinessSetting::query()->firstOrFail();

        // A whole-model dump: nullable columns that merely happen to be empty must stay out.
        app(AuditLogger::class)->record('business_settings_updated', $settings, $admin,
            newValues: $settings->getAttributes());
        $dump = $this->latestSettingsAudit();
        foreach (['business_phone', 'business_email', 'business_address', 'state', 'receipt_footer'] as $nullable) {
            $this->assertArrayNotHasKey($nullable, $dump->new_values ?? [],
                "A model dump must not record {$nullable} merely because the column is empty");
        }

        // An explicit diff: the supplied key survives as null, an unsupplied one never appears.
        app(AuditLogger::class)->record('business_settings_updated', $settings, $admin,
            ['state' => 'Anambra'], ['state' => null], explicitDiff: true);
        $diff = $this->latestSettingsAudit();
        $this->assertSame(['state' => null], $diff->new_values);
        $this->assertArrayNotHasKey('city', $diff->new_values);
    }

    public function test_the_sanitizer_still_refuses_non_scalar_and_non_allowlisted_values(): void
    {
        $admin = $this->admin();

        app(AuditLogger::class)->record('business_settings_updated', BusinessSetting::query()->firstOrFail(), $admin,
            newValues: [
                'business_name' => 'Kept',
                'business_address' => ['an', 'array'],
                'city' => (object) ['an' => 'object'],
                'state' => null,
                'legal_name' => 'Retired Legal Ltd',
                'password' => null,
                'api_key' => null,
                'webhook_secret' => 'whsec_live',
                'request_token' => null,
            ], explicitDiff: true);

        $log = $this->latestSettingsAudit();

        $this->assertEqualsCanonicalizing(['business_name', 'state'], array_keys($log->new_values));
        $this->assertSame('Kept', $log->new_values['business_name']);
        $this->assertNull($log->new_values['state']);
        foreach (['business_address', 'city', 'legal_name', 'password', 'api_key', 'webhook_secret', 'request_token'] as $rejected) {
            $this->assertArrayNotHasKey($rejected, $log->new_values,
                "{$rejected} must never reach the audit log");
        }
    }

    /* ------------------------------------------------------------- memo invalidation */

    public function test_the_write_action_invalidates_the_memoised_reader_without_the_controller(): void
    {
        $admin = $this->admin();
        $reader = app(BusinessSettings::class);
        $this->seedSettings($admin, []);
        $reader->forget();

        $this->assertSame('Inventra Smart Trade', $reader->current()->business_name);

        app(UpdateBusinessSettings::class)->execute($admin, ['business_name' => 'Renamed By Action']);

        $this->assertSame('Renamed By Action', $reader->current()->business_name,
            'A settings write must invalidate the memo even when the controller is not involved');
    }

    public function test_a_rolled_back_write_leaves_the_memoised_reader_intact(): void
    {
        $admin = $this->admin();
        $reader = app(BusinessSettings::class);
        $original = $reader->current()->business_name;

        $this->app->bind(AuditLogger::class, fn () => new class extends AuditLogger
        {
            public function record(string $action, Model $auditable, ?User $actor,
                array $oldValues = [], array $newValues = [], array $metadata = [],
                bool $explicitDiff = false): void
            {
                throw new RuntimeException('audit unavailable');
            }
        });

        try {
            app(UpdateBusinessSettings::class)->execute($admin, ['business_name' => 'Never Committed']);
            $this->fail('The write should have failed.');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame($original, $reader->current()->business_name,
            'A rolled-back write must not leave the reader serving a value that was never committed');
        $this->assertSame($original, DB::table('business_settings')->value('business_name'));
    }

    /* --------------------------------------------------------------- read-only & cost */

    public function test_loading_the_settings_page_writes_nothing(): void
    {
        $admin = $this->admin();
        $before = $this->fingerprint();

        foreach (range(1, 3) as $ignored) {
            $this->actingAs($admin)->get(route('settings.business.edit'))->assertOk();
        }

        $this->assertSame($before, $this->fingerprint(), 'A settings GET must not mutate anything');
    }

    public function test_settings_and_receipt_query_counts_stay_bounded(): void
    {
        $admin = $this->admin();
        $urls = $this->receiptUrls($admin);

        $get = $this->queryCount($admin, route('settings.business.edit'));
        $this->assertLessThanOrEqual(10, $get, "Settings GET issued {$get} queries");

        foreach ($urls as $label => $url) {
            $settingsQueries = $this->settingsQueryCount($admin, $url);
            $this->assertSame(1, $settingsQueries,
                "{$label} receipt must read business_settings exactly once per request, not {$settingsQueries} times");
        }
    }

    /* ------------------------------------------------------------------------ helpers */

    private function admin(string $name = 'Owner Admin'): User
    {
        return User::factory()->create(['role' => UserRole::Admin, 'name' => $name]);
    }

    /** Puts the singleton into a known state through the real write path. */
    private function seedSettings(User $admin, array $values): void
    {
        app(UpdateBusinessSettings::class)->execute($admin, $this->payload($values));
        app(BusinessSettings::class)->forget();
    }

    private function latestSettingsAudit(): AuditLog
    {
        return AuditLog::query()->where('action', 'business_settings_updated')->latest('id')->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'business_name' => 'Inventra Smart Trade', 'business_phone' => null,
            'business_email' => null, 'business_address' => null, 'city' => null, 'state' => null,
            'receipt_footer' => null,
        ], $overrides);
    }

    /** @return array<string, string> label => receipt url */
    private function receiptUrls(User $admin): array
    {
        return app(ReceiptFixture::class)->build($this, $admin);
    }

    private function fingerprint(): array
    {
        return collect(self::FINGERPRINTED)->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count().':'.hash('sha256', DB::table($table)->orderBy('id')->get()->toJson())])->all();
    }

    private function queryCount(User $user, string $url): int
    {
        $count = 0;
        DB::listen(function ($query) use (&$count): void {
            if (! str_contains($query->sql, '`sessions`')) {
                $count++;
            }
        });
        $this->actingAs($user)->get($url)->assertOk();
        DB::getEventDispatcher()->forget(QueryExecuted::class);

        return $count;
    }

    /**
     * Counts business_settings reads for one page. The request-scoped memo is dropped first: the
     * test process reuses one container across requests, so without this the memo from an earlier
     * request would hide the real per-request cost.
     */
    private function settingsQueryCount(User $user, string $url): int
    {
        app(BusinessSettings::class)->forget();
        $count = 0;
        DB::listen(function ($query) use (&$count): void {
            if (str_contains($query->sql, 'business_settings')) {
                $count++;
            }
        });
        $this->actingAs($user)->get($url)->assertOk();
        DB::getEventDispatcher()->forget(QueryExecuted::class);

        return $count;
    }
}
