<?php

namespace App\Observers;

use App\Alerts\OperationalAlertEvaluator;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Projects receivable and refundable-credit alerts after a Sale write commits.
 *
 * Sale aggregates are synchronised by payments, returns, refunds and voids, and every one of those
 * ends in a Sale save, so observing the Sale covers all of them without touching a single financial
 * Action. Deferred past commit for the same reason as ProductAlertObserver: the money is
 * authoritative, the alert is a projection of it.
 */
class SaleAlertObserver
{
    public function __construct(private readonly OperationalAlertEvaluator $evaluator) {}

    public function saved(Sale $sale): void
    {
        DB::afterCommit(function () use ($sale): void {
            try {
                $this->evaluator->evaluateSale($sale->fresh() ?? $sale);
            } catch (Throwable $exception) {
                // Bounded, structural detail only. A QueryException message carries the failing
                // SQL and its bindings, which for an alert insert means product or customer names
                // and money, so the message itself is deliberately not logged.
                Log::error('Operational alert projection failed for a Sale.', [
                    'sale_id' => $sale->getKey(),
                    'exception' => $exception::class,
                    'code' => (string) $exception->getCode(),
                ]);
            }
        });
    }
}
