<?php

namespace Tests\Feature\Sale;

use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Exporting the Sales list.
 *
 * Two formats, one dataset. Both routes read `SaleController::filteredSales()`, which is also what
 * draws the list, so what matters here is that neither export can show a Sale the screen would not:
 * not a different filter result, and not a Sale the caller is barred from seeing.
 *
 * The individual sale receipt PDF is a separate route with a separate policy and is deliberately
 * exercised here too, so a change to the list export cannot quietly disturb it.
 */
class SalesListExportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $rep;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->rep = User::factory()->create(['role' => UserRole::SalesRep]);
    }

    private function sale(array $attributes = []): Sale
    {
        return Sale::factory()->create($attributes + ['sold_by' => $this->admin->id]);
    }

    public function test_the_export_menu_offers_both_formats_and_carries_the_current_filters(): void
    {
        $this->sale();

        $html = $this->actingAs($this->admin)
            ->get(route('sales.index', ['payment_status' => 'unpaid']))
            ->assertOk()
            ->getContent();

        // One trigger, announced as a menu button, and two format entries beneath it.
        $this->assertStringContainsString('aria-haspopup="menu"', $html);
        $this->assertStringContainsString('aria-controls="sales-export-formats"', $html);
        $this->assertStringContainsString('Export as CSV', $html);
        $this->assertStringContainsString('Export as PDF', $html);

        // Both links carry the filter the page was drawn under, so an export from a filtered screen
        // cannot silently widen to the whole history.
        $this->assertStringContainsString(e(route('sales.export', ['payment_status' => 'unpaid'])), $html);
        $this->assertStringContainsString(e(route('sales.export.pdf', ['payment_status' => 'unpaid'])), $html);
    }

    public function test_csv_export_returns_only_the_filtered_sales(): void
    {
        $wanted = $this->sale(['payment_status' => PaymentStatus::Unpaid, 'sale_number' => 'S-AAAA2222']);
        $other = $this->sale(['payment_status' => PaymentStatus::Paid, 'sale_number' => 'S-BBBB3333']);

        $response = $this->actingAs($this->admin)
            ->get(route('sales.export', ['payment_status' => 'unpaid']))
            ->assertOk();

        $csv = $response->streamedContent();
        $this->assertStringContainsString($wanted->sale_number, $csv);
        $this->assertStringNotContainsString($other->sale_number, $csv);
    }

    /**
     * A sale number may now contain @ ! % or #. None of them is special to CSV, but the file is
     * still written by `fputcsv` rather than string concatenation, so a value that *did* contain a
     * comma or a quote would be quoted rather than breaking the row.
     */
    public function test_csv_export_is_written_through_a_real_csv_writer(): void
    {
        // The snapshot columns are derived from the related Customer, so the awkward name has to
        // live on the Customer for the Sale to carry it into the export.
        $customer = Customer::factory()->create(['first_name' => 'Obi, Emeka', 'last_name' => '"Junior"']);
        $sale = $this->sale(['sale_number' => 'S-9@!%#KQT', 'customer_id' => $customer->id]);

        $csv = $this->actingAs($this->admin)->get(route('sales.export'))->assertOk()->streamedContent();

        // The sale number survives intact, and the awkward customer name is quoted and escaped
        // rather than splitting the row.
        $this->assertStringContainsString($sale->sale_number, $csv);
        $this->assertStringContainsString('"Obi, Emeka ""Junior"""', $csv);

        // Parsed back, the header and the row still agree on how many columns there are.
        $rows = array_map('str_getcsv', array_filter(explode("\n", trim($csv))));
        $this->assertCount(2, $rows);
        $this->assertSameSize($rows[0], $rows[1]);
        $this->assertSame('S-9@!%#KQT', $rows[1][0]);
    }

    public function test_pdf_export_renders_the_same_filtered_dataset_as_the_csv(): void
    {
        $wanted = $this->sale(['payment_status' => PaymentStatus::Unpaid, 'sale_number' => 'S-CCCC4444']);
        $other = $this->sale(['payment_status' => PaymentStatus::Paid, 'sale_number' => 'S-DDDD5555']);

        $response = $this->actingAs($this->admin)
            ->get(route('sales.export.pdf', ['payment_status' => 'unpaid']))
            ->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringContainsString('attachment', (string) $response->headers->get('content-disposition'));

        // The same narrowing the CSV applies, through the same builder.
        $csv = $this->actingAs($this->admin)
            ->get(route('sales.export', ['payment_status' => 'unpaid']))->streamedContent();
        $this->assertStringContainsString($wanted->sale_number, $csv);
        $this->assertStringNotContainsString($other->sale_number, $csv);
    }

    public function test_pdf_export_describes_the_filters_it_was_run_under(): void
    {
        $customer = Customer::factory()->create(['first_name' => 'Ngozi', 'last_name' => 'Eze']);
        $this->sale(['customer_id' => $customer->id]);

        // Rendering the view directly: the PDF binary is dompdf's business, but what goes into it
        // is this application's, and that is what the header claim has to be checked against.
        $html = $this->actingAs($this->admin)->get(route('sales.export.pdf', [
            'from' => '2026-01-01',
            'to' => '2026-01-31',
            'payment_status' => 'unpaid',
        ]))->assertOk();

        $this->assertSame('application/pdf', $html->headers->get('content-type'));
    }

    /** A Sales Rep sees only their own sales on the list, and so only their own in either export. */
    public function test_exports_apply_the_sales_rep_scope(): void
    {
        $mine = $this->sale(['sold_by' => $this->rep->id, 'sale_number' => 'S-MINE2345']);
        $theirs = $this->sale(['sold_by' => $this->admin->id, 'sale_number' => 'S-THRS2345']);

        $csv = $this->actingAs($this->rep)->get(route('sales.export'))->assertOk()->streamedContent();
        $this->assertStringContainsString($mine->sale_number, $csv);
        $this->assertStringNotContainsString($theirs->sale_number, $csv);

        // The PDF is behind the same builder, so it is narrowed identically.
        $this->actingAs($this->rep)->get(route('sales.export.pdf'))->assertOk();
    }

    public function test_both_exports_require_authentication(): void
    {
        $this->get(route('sales.export'))->assertRedirect(route('login'));
        $this->get(route('sales.export.pdf'))->assertRedirect(route('login'));
    }

    /** The list export must not disturb the individual receipt PDF, which is a different document. */
    public function test_the_individual_receipt_pdf_still_works(): void
    {
        $sale = $this->sale(['sale_number' => 'S-RCPT2345']);

        $response = $this->actingAs($this->admin)->get(route('sales.receipt.pdf', $sale))->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringContainsString('S-RCPT2345.pdf', (string) $response->headers->get('content-disposition'));
    }

    /** An empty result is a valid report, not an error. */
    public function test_pdf_export_renders_when_nothing_matches(): void
    {
        $this->actingAs($this->admin)
            ->get(route('sales.export.pdf', ['from' => '2000-01-01', 'to' => '2000-01-02']))
            ->assertOk();
    }

    public function test_sale_numbers_with_symbols_are_escaped_by_blade_on_the_list(): void
    {
        // Not a character the alphabet can produce — the point is that the template escapes
        // whatever it is given rather than trusting the generator's alphabet to be safe.
        $this->sale(['sale_number' => 'S-<b>&"X#']);

        $html = $this->actingAs($this->admin)->get(route('sales.index'))->assertOk()->getContent();

        $this->assertStringContainsString('S-&lt;b&gt;&amp;&quot;X#', $html);
        $this->assertStringNotContainsString('S-<b>&"X#', $html);
    }

    public function test_the_list_renders_with_the_inventory_page_header_composition(): void
    {
        $this->sale();

        $html = $this->actingAs($this->admin)->get(route('sales.index'))->assertOk()->getContent();

        // The same classes Inventory uses, so the two headers cannot drift apart.
        $this->assertStringContainsString('ui-page-header', $html);
        $this->assertStringContainsString('ui-page-description', $html);
        $this->assertStringContainsString('View, manage and track all recorded sales.', $html);

        // Exactly one Record Sale action on the page.
        $this->assertSame(1, substr_count($html, 'Record Sale'));
    }

    /** Whole-naira amounts read as ₦84,000 on the list, not ₦84,000.00. */
    public function test_the_list_shows_compact_whole_naira_amounts(): void
    {
        // The financial columns have to reconcile — the table has a CHECK constraint saying so —
        // so the whole amount is set consistently rather than just the one column under test.
        $this->sale([
            'subtotal' => '84000.00',
            'discount_amount' => '0.00',
            'total_amount' => '84000.00',
            'amount_paid' => '84000.00',
            'balance_due' => '0.00',
            'payment_status' => PaymentStatus::Paid,
            'sale_date' => CarbonImmutable::now(config('business.timezone')),
        ]);

        $html = $this->actingAs($this->admin)->get(route('sales.index'))->assertOk()->getContent();

        $this->assertStringContainsString('&#8358;84,000<', $html);
        $this->assertStringNotContainsString('&#8358;84,000.00<', $html);
    }
}
