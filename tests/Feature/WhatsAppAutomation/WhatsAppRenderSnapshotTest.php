<?php

namespace Tests\Feature\WhatsAppAutomation;

use App\Enums\UserRole;
use App\Models\User;
use App\Models\WhatsAppAutomation;
use App\Models\WhatsAppConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Renders the module's real pages to disk so they can be opened in a browser for visual and
 * responsive checking. It asserts the structure the Figma requires; the files are a by-product.
 *
 * Writes only to the scratchpad, never into `public/`.
 */
class WhatsAppRenderSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private const OUT = '/private/tmp/claude-501/-Users-apple-Sites-inventra/75bd0aad-2c29-4585-98d4-2842195925de/scratchpad';

    public function test_it_renders_both_states(): void
    {
        $this->assertSame('inventra_test', DB::connection()->getDatabaseName());

        $admin = User::factory()->create(['role' => UserRole::Admin, 'name' => 'John Doe', 'phone' => '+2348012345678']);

        // ── Disconnected ──
        $html = $this->actingAs($admin)->get(route('whatsapp.automation.index'))->assertOk()->getContent();
        file_put_contents(self::OUT.'/wa-disconnected.html', $html);

        $this->assertStringContainsString('WhatsApp automation', $html);
        $this->assertStringContainsString("WhatsApp isn't connected yet", $html);
        $this->assertStringContainsString('Connect Business WhatsApp to continue automation', $html);
        // All three cards show the empty state.
        $this->assertSame(3, substr_count($html, 'wa-empty-title'));

        // Exactly one connection CTA on the page, in the header, using the shared primary token.
        // The cards are informational only: three identical buttons read as three different
        // actions, which is what this replaced.
        $this->assertSame(1, substr_count($html, 'Connect business number'));
        $this->assertStringContainsString('class="inventra-primary-action" x-on:click="openConnect"', $html);
        $this->assertStringNotContainsString('wa-empty-action', $html);
        $this->assertStringNotContainsString('>Connect WhatsApp<', $html);
        // And the connection modal is present with its three steps.
        $this->assertStringContainsString('Which number should we connect?', $html);
        $this->assertStringContainsString('+234 700 000 1234', $html);
        $this->assertStringContainsString('The number must have WhatsApp installed', $html);
        // Step 1 promises only what Meta actually does next; Inventra sends no code of its own.
        $this->assertStringContainsString('confirm it with Meta in the next step', $html);
        $this->assertStringNotContainsString("We'll send a 6-digit code", $html);

        // Step 2 is Meta's Embedded Signup, not an Inventra-issued code. The OTP markup and its
        // resend affordance must be gone: Inventra cannot grant a sending identity, only Meta can.
        $this->assertStringContainsString('Connect through Meta', $html);
        $this->assertStringContainsString('Continue with Meta', $html);
        $this->assertStringNotContainsString('wa-otp', $html);
        $this->assertStringNotContainsString('Enter the code we sent', $html);
        $this->assertStringNotContainsString('Resend code', $html);
        // And nothing claims a connection while disconnected.
        $this->assertStringNotContainsString('Business number connected', $html);

        // ── Connected ──
        WhatsAppConnection::query()->firstOrCreate(['singleton_key' => 'whatsapp'])->forceFill([
            'provider' => 'meta',
            'waba_id' => '100000000000001',
            'phone_number_id' => '200000000000001',
            'display_phone_number' => '+234 700 000 1234',
            'phone_number' => '+2347000001234',
            'access_token' => 'render-fixture-token',
            'status' => 'connected',
            'verified_at' => now(),
            'connected_at' => now(),
        ])->save();
        WhatsAppAutomation::query()->whereIn('key', ['welcome', 'post_purchase', 'low_stock'])->update([
            'enabled' => true, 'template_status' => 'APPROVED', 'template_name' => 'inventra_render',
        ]);

        $html = $this->actingAs($admin)->get(route('whatsapp.automation.index'))->assertOk()->getContent();
        file_put_contents(self::OUT.'/wa-connected.html', $html);

        // Connected: the indicator replaces the button. Never both at once.
        $this->assertStringContainsString('Business number connected', $html);
        $this->assertStringNotContainsString('Connect business number', $html);
        foreach ([
            'Welcome message', "Greet new customers the first time they're added.",
            'Post-purchase message', 'Thank customers automatically after every paid sale.',
            'Pickup reminder', 'Remind customers when their order is ready for pickup.',
            'Low-stock alert to manager', 'Message the manager when any product hits its reorder level.',
        ] as $copy) {
            $this->assertStringContainsString(e($copy), $html);
        }

        // Template statuses derive from the automations' real enabled state.
        $this->assertSame(3, substr_count($html, 'wa-badge is-active'));
        $this->assertSame(1, substr_count($html, 'wa-badge is-inactive'));
        // Four row switches plus the editor modal's own, and four Edit/Preview pairs.
        $this->assertSame(5, substr_count($html, 'wa-switch-track'));
        $this->assertSame(4, substr_count($html, '>Edit</button>'));
        $this->assertSame(4, substr_count($html, '>Preview</button>'));
        // Log columns and filters.
        foreach (['Sent', 'Customer', 'Type', 'Status', 'By'] as $column) {
            $this->assertStringContainsString('>'.$column.'</th>', $html);
        }
        $this->assertStringContainsString('Filter by type', $html);
        $this->assertStringContainsString('Filter by status', $html);

        // The editor states where the mapped Meta template stands, so nothing implies that typed
        // text is what WhatsApp delivers.
        $this->assertStringContainsString('wa-template-state', $html);
        $this->assertStringContainsString('is a <b>draft</b>', $html);

        // The connection's token never reaches the page.
        $this->assertStringNotContainsString('render-fixture-token', $html);
    }
}
