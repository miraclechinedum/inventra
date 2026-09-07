<?php

namespace App\Observers;

use App\Alerts\OperationalAlertEvaluator;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Projects low-stock alerts immediately after a Product write commits.
 *
 * Registered centrally rather than called from each Action, so no inventory, sale, purchase or
 * return code path carries alert logic. The work is deferred with DB::afterCommit, which means it
 * runs outside the business transaction and is discarded entirely if that transaction rolls back —
 * a failing projection can never fail or undo the stock movement that triggered it. Anything missed
 * here is repaired by reconciliation, because alerts are derived state.
 */
class ProductAlertObserver
{
    public function __construct(private readonly OperationalAlertEvaluator $evaluator) {}

    public function saved(Product $product): void
    {
        $this->project($product);
    }

    public function deleted(Product $product): void
    {
        $this->project($product);
    }

    public function restored(Product $product): void
    {
        $this->project($product);
    }

    private function project(Product $product): void
    {
        DB::afterCommit(function () use ($product): void {
            try {
                $this->evaluator->evaluateProduct($product->fresh() ?? $product);
            } catch (Throwable $exception) {
                // Never re-thrown: the business write already committed and is authoritative.
                // Bounded, structural detail only. A QueryException message carries the failing
                // SQL and its bindings, which for an alert insert means product or customer names
                // and money, so the message itself is deliberately not logged.
                Log::error('Operational alert projection failed for a Product.', [
                    'product_id' => $product->getKey(),
                    'exception' => $exception::class,
                    'code' => (string) $exception->getCode(),
                ]);
            }
        });
    }
}
