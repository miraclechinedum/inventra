<?php

namespace Tests\Feature\WhatsAppAutomation;

use App\Enums\UserRole;
use App\Models\Product;
use App\Models\User;
use App\Models\WhatsAppAutomation;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The WhatsApp message log.
 *
 * The property these tests exist to hold is the read/write split. A Manager reads the log and is
 * refused every mutating WhatsApp route — and that refusal is structural: `viewLogs` is a separate
 * ability from `viewAny`, so widening the log can never widen the Automation module with it. A test
 * that only checked for a missing button would pass while the endpoint stayed open.
 */
class WhatsAppLogAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private User $rep;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame(
            'inventra_test',
            DB::connection()->getDatabaseName(),
            'Refusing to run: these tests write, and this is not the test database.'
        );

        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->manager = User::factory()->create(['role' => UserRole::Manager]);
        $this->rep = User::factory()->create(['role' => UserRole::SalesRep]);
    }

    /** One persisted message in a known state. */
    private function message(array $overrides = []): WhatsAppMessage
    {
        static $sequence = 0;
        $sequence++;

        $connection = WhatsAppConnection::query()->firstOrCreate(
            ['singleton_key' => 'whatsapp'],
            ['provider' => 'fake', 'status' => 'disconnected'],
        );

        $message = new WhatsAppMessage;
        $message->forceFill(array_merge([
            'business_id' => $connection->business_id,
            'whatsapp_connection_id' => $connection->id,
            'type' => WhatsAppAutomation::WELCOME,
            'recipient_name' => 'Ngozi Eze',
            'destination_phone' => '+234805564'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
            'body' => 'Hi Ngozi Eze, welcome.',
            'idempotency_key' => 'log-test:'.$sequence,
            'origin' => 'automatic',
            'status' => WhatsAppMessage::STATUS_DELIVERED,
            'queued_at' => now(),
            'sent_at' => now(),
            'delivered_at' => now(),
            'attempt' => 1,
        ], $overrides))->save();

        return $message;
    }

    // ── Access ──────────────────────────────────────────────────────────────────────────────────

    public function test_an_administrator_and_a_manager_may_read_the_log(): void
    {
        $this->message();

        foreach ([$this->admin, $this->manager] as $user) {
            $this->actingAs($user)->get(route('whatsapp.logs.index'))->assertOk();
        }
    }

    /** A Sales Representative is outside this module entirely. */
    public function test_a_sales_representative_is_refused(): void
    {
        $this->actingAs($this->rep)->get(route('whatsapp.logs.index'))->assertForbidden();
    }

    public function test_a_guest_is_sent_to_the_login_screen(): void
    {
        $this->get(route('whatsapp.logs.index'))->assertRedirect(route('login'));
    }

    /**
     * Reading the log never grants the Automation module.
     *
     * `viewLogs` and `viewAny` are deliberately separate abilities; this is the assertion that
     * would fail if someone collapsed them.
     */
    public function test_reading_the_log_does_not_grant_the_automation_module(): void
    {
        $this->assertTrue($this->manager->can('viewLogs', WhatsAppAutomation::class));
        $this->assertFalse($this->manager->can('viewAny', WhatsAppAutomation::class));
        $this->assertFalse($this->manager->can('configure', WhatsAppAutomation::class));
        $this->assertFalse($this->manager->can('connect', WhatsAppAutomation::class));
        $this->assertFalse($this->manager->can('sendTest', WhatsAppAutomation::class));
        $this->assertFalse($this->manager->can('retry', WhatsAppAutomation::class));

        $this->actingAs($this->manager)->get(route('whatsapp.automation.index'))->assertForbidden();
    }

    /** A Manager cannot retry, and the refusal is the server's, not a hidden button's. */
    public function test_a_manager_cannot_retry_a_failed_message(): void
    {
        $failed = $this->message([
            'status' => WhatsAppMessage::STATUS_FAILED,
            'failed_at' => now(), 'delivered_at' => null,
            'failure_reason' => 'Recipient number is not on WhatsApp.',
        ]);

        $this->actingAs($this->manager)
            ->post(route('whatsapp.messages.retry', $failed))
            ->assertForbidden();

        $this->assertSame(WhatsAppMessage::STATUS_FAILED, $failed->refresh()->status);
        $this->assertSame(0, WhatsAppMessage::query()->where('retry_of_id', $failed->id)->count());
    }

    /** The log page offers a Manager no retry control at all. */
    public function test_the_page_shows_a_manager_no_retry_control(): void
    {
        $failed = $this->message([
            'status' => WhatsAppMessage::STATUS_FAILED, 'failed_at' => now(), 'delivered_at' => null,
        ]);

        $html = $this->actingAs($this->manager)
            ->get(route('whatsapp.logs.index', ['message' => $failed->id, 'range' => 'all']))
            ->assertOk()->getContent();

        $this->assertStringNotContainsString(route('whatsapp.messages.retry', $failed), $html);
        $this->assertStringContainsString('Only an Admin can retry a failed message.', $html);
        $this->assertStringContainsString('View only', $html);
    }

    // ── Summary ─────────────────────────────────────────────────────────────────────────────────

    /** The cards count real rows, and `read` counts as delivered rather than being lost. */
    public function test_the_summary_cards_count_real_messages(): void
    {
        $this->message(['status' => WhatsAppMessage::STATUS_DELIVERED]);
        $this->message(['status' => WhatsAppMessage::STATUS_READ, 'read_at' => now()]);
        $this->message(['status' => WhatsAppMessage::STATUS_QUEUED, 'sent_at' => null, 'delivered_at' => null]);
        $this->message(['status' => WhatsAppMessage::STATUS_FAILED, 'failed_at' => now(), 'delivered_at' => null]);

        $html = $this->actingAs($this->manager)->get(route('whatsapp.logs.index'))->assertOk()->getContent();

        // Two delivered (delivered + read), one pending (queued), one failed.
        $this->assertMatchesRegularExpression('/Delivered<\/p>\s*<strong class="is-green">2</', $html);
        $this->assertMatchesRegularExpression('/Pending<\/p>\s*<strong class="is-amber">1</', $html);
        $this->assertMatchesRegularExpression('/Failed<\/p>\s*<strong class="is-red">1</', $html);
    }

    // ── Filters ─────────────────────────────────────────────────────────────────────────────────

    public function test_search_matches_the_recipient_name_and_number(): void
    {
        $this->message(['recipient_name' => 'Findable Person', 'destination_phone' => '+2348099998888']);
        $this->message(['recipient_name' => 'Someone Else', 'destination_phone' => '+2348011112222']);

        foreach (['Findable', '+234809999'] as $term) {
            $html = $this->actingAs($this->manager)
                ->get(route('whatsapp.logs.index', ['search' => $term, 'range' => 'all']))
                ->assertOk()->getContent();

            $this->assertStringContainsString('Findable Person', $html);
            $this->assertStringNotContainsString('Someone Else', $html);
        }
    }

    public function test_the_filters_intersect(): void
    {
        $this->message(['recipient_name' => 'Target One', 'type' => WhatsAppAutomation::WELCOME,
            'status' => WhatsAppMessage::STATUS_FAILED, 'failed_at' => now(), 'delivered_at' => null]);
        $this->message(['recipient_name' => 'Target Two', 'type' => WhatsAppAutomation::WELCOME,
            'status' => WhatsAppMessage::STATUS_DELIVERED]);

        $html = $this->actingAs($this->manager)->get(route('whatsapp.logs.index', [
            'search' => 'Target', 'type' => WhatsAppAutomation::WELCOME,
            'status' => WhatsAppMessage::STATUS_FAILED, 'range' => 'all',
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('Target One', $html);
        $this->assertStringNotContainsString('Target Two', $html);
    }

    /** "Pending" covers both real pre-delivery states. */
    public function test_the_pending_filter_covers_queued_and_sent(): void
    {
        $this->message(['recipient_name' => 'Queued Row', 'status' => WhatsAppMessage::STATUS_QUEUED,
            'sent_at' => null, 'delivered_at' => null]);
        $this->message(['recipient_name' => 'Sent Row', 'status' => WhatsAppMessage::STATUS_SENT, 'delivered_at' => null]);
        $this->message(['recipient_name' => 'Delivered Row']);

        $html = $this->actingAs($this->manager)
            ->get(route('whatsapp.logs.index', ['status' => 'pending', 'range' => 'all']))
            ->assertOk()->getContent();

        $this->assertStringContainsString('Queued Row', $html);
        $this->assertStringContainsString('Sent Row', $html);
        $this->assertStringNotContainsString('Delivered Row', $html);
    }

    /** A tampered filter degrades to the unfiltered list rather than erroring or leaking. */
    public function test_unknown_and_array_shaped_filters_are_discarded(): void
    {
        $this->message(['recipient_name' => 'Still Listed']);

        foreach ([['status' => 'bogus'], ['type' => 'bogus'], ['range' => 'bogus']] as $query) {
            $html = $this->actingAs($this->manager)
                ->get(route('whatsapp.logs.index', $query + ['range' => 'all']))
                ->assertOk()->getContent();

            $this->assertStringContainsString('Still Listed', $html);
        }

        $this->actingAs($this->manager)
            ->get(route('whatsapp.logs.index').'?status[]=failed&type[]=welcome')
            ->assertOk();
    }

    /** A typed LIKE wildcard searches for that character rather than matching everything. */
    public function test_like_wildcards_in_the_search_are_escaped(): void
    {
        $this->message(['recipient_name' => 'Ordinary Name']);

        $html = $this->actingAs($this->manager)
            ->get(route('whatsapp.logs.index', ['search' => '%%', 'range' => 'all']))
            ->assertOk()->getContent();

        $this->assertStringNotContainsString('Ordinary Name', $html);
    }

    // ── Detail panel ────────────────────────────────────────────────────────────────────────────

    public function test_the_panel_shows_the_stored_body_and_failure_reason(): void
    {
        $failed = $this->message([
            'status' => WhatsAppMessage::STATUS_FAILED, 'failed_at' => now(), 'delivered_at' => null,
            'body' => 'Hi there, welcome aboard.',
            'failure_reason' => 'Recipient number is not on WhatsApp.',
            'failure_code' => '131026',
        ]);

        $html = $this->actingAs($this->manager)
            ->get(route('whatsapp.logs.index', ['message' => $failed->id, 'range' => 'all']))
            ->assertOk()->getContent();

        $this->assertStringContainsString('Message detail', $html);
        $this->assertStringContainsString('Hi there, welcome aboard.', $html);
        $this->assertStringContainsString('Why it failed', $html);
        $this->assertStringContainsString('Recipient number is not on WhatsApp.', $html);
        // Provider internals stay internal: the code is stored but never rendered.
        $this->assertStringNotContainsString('131026', $html);
    }

    /** A failure with no provider reason still explains itself, without inventing one. */
    public function test_a_failure_without_a_reason_renders_a_safe_fallback(): void
    {
        $failed = $this->message([
            'status' => WhatsAppMessage::STATUS_FAILED, 'failed_at' => now(),
            'delivered_at' => null, 'failure_reason' => null,
        ]);

        $html = $this->actingAs($this->manager)
            ->get(route('whatsapp.logs.index', ['message' => $failed->id, 'range' => 'all']))
            ->assertOk()->getContent();

        $this->assertStringContainsString('WhatsApp did not accept this message.', $html);
    }

    /** A low-stock alert links to the real product through its stored subject. */
    public function test_a_low_stock_message_links_to_the_product_it_names(): void
    {
        $product = Product::factory()->create(['name' => 'Brake pads (front)', 'current_stock' => '2.000', 'reorder_level' => '8.000']);
        $message = $this->message([
            'type' => WhatsAppAutomation::LOW_STOCK,
            'recipient_name' => 'Floor Manager',
            'subject_type' => 'product', 'subject_id' => $product->id,
            'body' => 'Brake pads (front) is low.',
        ]);

        $html = $this->actingAs($this->manager)
            ->get(route('whatsapp.logs.index', ['message' => $message->id, 'range' => 'all']))
            ->assertOk()->getContent();

        $this->assertStringContainsString('Low-stock alert received', $html);
        $this->assertStringContainsString('Brake pads (front)', $html);
        $this->assertStringContainsString('Preview low-stock', $html);
        // The existing product page, not a reimplementation.
        $this->assertStringContainsString(route('inventory.products.show', $product), $html);
    }

    /** A hostile recipient name is escaped, not executed. */
    public function test_hostile_stored_text_is_escaped(): void
    {
        $message = $this->message(['recipient_name' => '<script>alert(1)</script>']);

        $html = $this->actingAs($this->manager)
            ->get(route('whatsapp.logs.index', ['message' => $message->id, 'range' => 'all']))
            ->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    /** No provider credential can reach this screen. */
    public function test_no_connection_secret_is_rendered(): void
    {
        // A CHECK constraint refuses a `connected` row that could not actually send, so the whole
        // identity is set rather than the status alone.
        WhatsAppConnection::query()->firstOrCreate(['singleton_key' => 'whatsapp'], ['provider' => 'fake', 'status' => 'disconnected'])
            ->forceFill([
                'provider' => 'fake', 'waba_id' => '100000000000001', 'phone_number_id' => 'PHONE_A',
                'display_phone_number' => '+234 700 000 1234', 'access_token' => 'SUPERSECRETTOKEN',
                'status' => 'connected', 'verified_at' => now(), 'connected_at' => now(),
            ])->save();
        $this->message();

        $html = $this->actingAs($this->manager)->get(route('whatsapp.logs.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('SUPERSECRETTOKEN', $html);
        $this->assertStringNotContainsString('access_token', $html);
    }

    // ── Listing ─────────────────────────────────────────────────────────────────────────────────

    public function test_the_log_is_paginated_and_carries_its_filters(): void
    {
        foreach (range(1, 14) as $index) {
            $this->message(['recipient_name' => 'Row '.$index]);
        }

        $response = $this->actingAs($this->manager)
            ->get(route('whatsapp.logs.index', ['range' => 'all', 'search' => 'Row']))
            ->assertOk();

        $this->assertCount(10, $response->viewData('messages')->items());
        $this->assertStringContainsString('search=Row', $response->getContent());
    }

    /** Nothing on the page is a Figma sample value. */
    public function test_an_empty_log_renders_zeroes_and_no_sample_data(): void
    {
        $html = $this->actingAs($this->manager)->get(route('whatsapp.logs.index'))->assertOk()->getContent();

        foreach (['148', '141', 'Ngozi Eze', 'Tunde Bakare', 'AutoParts NG', '+234 805 646 410'] as $sample) {
            $this->assertStringNotContainsString($sample, $html, "{$sample} must come from data, never the markup");
        }
    }
}
