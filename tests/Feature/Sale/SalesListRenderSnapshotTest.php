<?php

namespace Tests\Feature\Sale;

use App\Actions\Sale\CreateSale;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Support\Money;
use App\Support\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Renders the Sales list to a file so the page can be inspected in a real browser at real widths
 * without touching the development database.
 *
 * Skipped unless SALES_SNAPSHOT_DIR is set, so it costs nothing in an ordinary run. It asserts
 * nothing about appearance — a test cannot see — it only produces the artefact a human looks at.
 */
class SalesListRenderSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_render_the_sales_list_for_visual_inspection(): void
    {
        $directory = env('SALES_SNAPSHOT_DIR');

        if (! is_string($directory) || $directory === '') {
            $this->markTestSkipped('Set SALES_SNAPSHOT_DIR to render the page.');
        }

        $admin = User::factory()->create(['role' => UserRole::Admin, 'name' => 'Chidi Nwosu']);
        $today = CarbonImmutable::now(config('business.timezone'));

        // Real products, so the panel's line rows carry real frozen snapshots rather than anything
        // written straight into the items table.
        $catalogue = [
            'Camry oil filter' => '3500.00',
            'Air filter' => '7000.00',
            'Brake pad set (front)' => '18000.00',
            'Spark plug' => '2400.00',
        ];
        $products = [];

        foreach ($catalogue as $name => $price) {
            $products[$name] = Product::factory()->create([
                'name' => $name, 'selling_price' => $price, 'current_stock' => '500.000',
            ]);
        }

        // A row of each kind the panel has to render: a walk-in, a multi-line sale, a long customer
        // name, a long item list that must scroll, and each payment state.
        $rows = [
            ['name' => null, 'lines' => [['Camry oil filter', '2']], 'paid' => 'part', 'day' => 0],
            ['name' => ['Ngozi', 'Eze'], 'lines' => [['Brake pad set (front)', '3'], ['Spark plug', '4']], 'paid' => 'none', 'day' => 1],
            ['name' => ['Tunde', 'Bakare'], 'lines' => [['Camry oil filter', '2'], ['Air filter', '5']], 'paid' => 'full', 'day' => 1],
            ['name' => ['Emeka', 'Obi'], 'lines' => [['Air filter', '1']], 'paid' => 'full', 'day' => 2],
            // Deliberately long, to prove the items region scrolls and the footer stays reachable.
            ['name' => ['Oluwaseun', 'Adebayo-Ogundimu'], 'lines' => [
                ['Camry oil filter', '4'], ['Air filter', '3'], ['Brake pad set (front)', '2'],
                ['Spark plug', '8'], ['Camry oil filter', '1'],
            ], 'paid' => 'part', 'day' => 3],
        ];

        foreach ($rows as $row) {
            $customer = $row['name'] === null ? null : Customer::factory()->create([
                'first_name' => $row['name'][0], 'last_name' => $row['name'][1], 'is_active' => true,
            ]);

            $lines = array_map(fn (array $line): array => [
                'product_id' => $products[$line[0]]->id,
                'quantity' => $line[1],
            ], $row['lines']);

            // The amount tendered at the till, which is what CreateSale takes and what decides the
            // payment status. Derived from the same prices the action will charge, so the figure is
            // the sale's own rather than one invented alongside it.
            $total = array_reduce($row['lines'], fn (string $carry, array $line): string => bcadd(
                $carry,
                bcmul((string) $products[$line[0]]->selling_price, $line[1], 2),
                2
            ), '0.00');

            $paid = match ($row['paid']) {
                'full' => $total,
                'part' => bcdiv($total, '2', 2),
                default => '0.00',
            };

            // Built through the real action, so every total, snapshot and stock movement is the
            // application's own — nothing about this sale is fabricated for the picture.
            app(CreateSale::class)->execute($admin, [
                'is_walk_in' => $customer === null,
                'customer_id' => $customer?->id,
                'sale_date' => $today->subDays($row['day'])->toDateString(),
                'products' => $lines,
                'payment_method' => 'cash',
                'amount_paid' => $paid,
            ]);
        }

        $html = $this->actingAs($admin)->get(route('sales.index'))->assertOk()->getContent();

        // The lines endpoint the panel fetches from is same-origin and session-authenticated, which
        // a saved file is not. A tiny stub answers it so the rendered artefact shows the panel as a
        // signed-in operator sees it, with this sale's real line items.
        $lines = [];

        foreach (Sale::query()->with('items')->get() as $recorded) {
            $lines[route('sales.lines', $recorded, false)] = [
                'lines' => $recorded->items->map(fn ($item): array => [
                    'name' => $item->product_name_snapshot,
                    'quantity' => Quantity::trim((string) $item->quantity),
                    'total' => Money::compact((string) $item->line_total),
                ])->all(),
            ];
        }

        // The return fragment the panel fetches, captured per sale so the artefact can show the
        // return form exactly as a signed-in operator sees it.
        $fragments = [];

        foreach (Sale::query()->get() as $recorded) {
            $fragments[route('sales.returns.panel', $recorded, false)] = $this
                ->actingAs($admin)
                ->get(route('sales.returns.panel', $recorded))
                ->getContent();
        }

        $stub = '<script>window.__fragments = '.json_encode($fragments).';'
            .'window.__lines = '.json_encode($lines).';'
            .'const realFetch = window.fetch;'
            .'window.fetch = (url, options) => {'
            .'  const path = new URL(url, location.origin).pathname;'
            .'  if (window.__lines[path]) {'
            .'    return Promise.resolve(new Response(JSON.stringify(window.__lines[path]), {'
            .'      status: 200, headers: {"Content-Type": "application/json"}}));'
            .'  }'
            .'  if (window.__fragments[path]) {'
            .'    return Promise.resolve(new Response(window.__fragments[path], {'
            .'      status: 200, headers: {"Content-Type": "text/html"}}));'
            .'  }'
            .'  return realFetch(url, options);'
            .'};</script>';

        $html = str_replace('</head>', $stub.'</head>', $html);

        // The snapshot is served over HTTP from the `public/` directory, so asset URLs are
        // rewritten to be root-relative and resolve against that server rather than the test host.
        $html = str_replace(
            ['http://localhost/build/', 'http://inventra.test/build/'],
            '/build/',
            $html
        );

        // Written only where it was asked for. An earlier version also dropped a copy into
        // `public/` so a browser could load the compiled stylesheet by relative path; that left an
        // untracked file in the repository, so the caller serves the artefact themselves instead.
        file_put_contents(rtrim($directory, '/').'/sales-index.html', $html);
        $this->addToAssertionCount(1);
    }
}
