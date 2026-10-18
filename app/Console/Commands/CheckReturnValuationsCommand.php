<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\Sale;
use App\Support\ReturnValuation;
use App\Tenancy\CurrentBusiness;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reports, and never repairs, returns recorded on discounted Sales before they were valued net of
 * the discount.
 *
 * Such returns were valued at the undiscounted price, so they may have credited the customer more
 * than the goods were sold for. For each affected line this compares what was recorded with what
 * ReturnValuation gives for the same total quantity today. It reads only: recorded returns,
 * refunds and balances are immutable evidence, and any correction needs its own reviewed workflow.
 */
class CheckReturnValuationsCommand extends Command
{
    protected $signature = 'inventra:check-return-valuations';

    protected $description = 'Report returns on discounted Sales that were valued before discount-aware valuation (read-only)';

    public function handle(CurrentBusiness $tenancy): int
    {
        $affected = 0;

        foreach (Business::query()->orderBy('id')->cursor() as $business) {
            $tenancy->run($business, function () use ($business, &$affected): void {
                $sales = Sale::query()
                    ->where('discount_amount', '>', 0)
                    ->whereExists(fn ($query) => $query->select(DB::raw(1))->from('sale_return_items as ri')
                        ->join('sale_items as si', 'si.id', '=', 'ri.sale_item_id')
                        ->whereColumn('si.sale_id', 'sales.id')
                        ->whereNull('ri.gross_line_value'))
                    ->orderBy('id')
                    ->get();

                foreach ($sales as $sale) {
                    $items = $sale->items()->get()->keyBy('id');
                    $shares = ReturnValuation::shares($sale, $items);
                    $returned = ReturnValuation::prior((int) $sale->business_id, $items->keys()->all());
                    $difference = '0.00';

                    foreach ($returned as $itemId => $row) {
                        $item = $items->get($itemId);

                        if ($item === null) {
                            continue;
                        }

                        $expected = ReturnValuation::line($item, $shares[$itemId] ?? '0.00', $row['quantity'], '0.000', '0.00')['value'];
                        $difference = bcadd($difference, bcsub($row['value'], $expected, 2), 2);
                    }

                    $affected++;
                    $this->line("Business {$business->id} · Sale {$sale->sale_number}: recorded return value exceeds the net value by {$difference}");
                }
            });
        }

        $this->info('Discounted Sales with returns valued before discount-aware valuation: '.$affected);

        return self::SUCCESS;
    }
}
