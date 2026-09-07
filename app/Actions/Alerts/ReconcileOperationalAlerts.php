<?php

namespace App\Actions\Alerts;

use App\Alerts\OperationalAlertEvaluator;
use App\Alerts\OperationalAlertProjector;
use App\Enums\OperationalAlertType;
use App\Models\Product;
use App\Models\Sale;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Rebuilds the current active-alert set from source truth.
 *
 * Alerts are a derived projection, so this is the repair mechanism that makes immediate projection
 * optional rather than load-bearing: whatever a domain write missed, a later run notices. It reads
 * business records and writes only alert tables — it never repairs stock, settles a balance or
 * touches a financial row.
 *
 * Idempotent by construction: opening an alert that already exists is a no-op refresh guarded by the
 * UNIQUE active_key, and resolving one that is already resolved finds nothing to do.
 */
class ReconcileOperationalAlerts
{
    public function __construct(
        private readonly OperationalAlertEvaluator $evaluator,
        private readonly OperationalAlertProjector $projector,
    ) {}

    /** @return array{evaluated:int, resolved:int, failed:int} */
    public function execute(): array
    {
        $evaluated = 0;
        $failed = 0;

        // Every Product, not just the low ones: a Product that recovered needs its alert resolved,
        // and only looking at currently-low stock would never see it.
        foreach (Product::query()->withTrashed()->orderBy('id')->cursor() as $product) {
            $failed += $this->guard(fn () => $this->evaluator->evaluateProduct($product), 'product', (int) $product->getKey());
            $evaluated++;
        }

        foreach (Sale::query()->orderBy('id')->cursor() as $sale) {
            $failed += $this->guard(fn () => $this->evaluator->evaluateSale($sale), 'sale', (int) $sale->getKey());
            $evaluated++;
        }

        $failed += $this->guard(fn () => $this->evaluator->evaluateIntegrity(), 'integrity', 0);

        // A subject that disappeared entirely (a hard-deleted row) leaves an active alert pointing
        // at nothing; close those so the operational list only shows conditions that still exist.
        $resolved = $this->resolveOrphans();

        return ['evaluated' => $evaluated, 'resolved' => $resolved, 'failed' => $failed];
    }

    private function resolveOrphans(): int
    {
        $resolved = 0;

        $resolved += $this->projector->resolveMissing(
            OperationalAlertType::InventoryLowStock, 'product',
            Product::query()->withTrashed()->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
        );

        foreach ([OperationalAlertType::SaleReceivableOutstanding, OperationalAlertType::SaleRefundableCredit, OperationalAlertType::DataIntegrityWarning] as $type) {
            $resolved += $this->projector->resolveMissing(
                $type, 'sale',
                Sale::query()->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
            );
        }

        return $resolved;
    }

    /**
     * One malformed subject must not abandon the rest of the sweep, but it must not vanish either:
     * the failure is logged and counted, and the next run retries it.
     */
    private function guard(callable $work, string $subjectType, int $subjectId): int
    {
        try {
            $work();

            return 0;
        } catch (Throwable $exception) {
            // Which subject failed and how, with nothing copied out of the exception message: a
            // QueryException embeds the SQL and its bindings, and those bindings carry product and
            // customer names and amounts.
            Log::error('Operational alert reconciliation failed for a subject.', [
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'exception' => $exception::class,
                'code' => (string) $exception->getCode(),
            ]);

            return 1;
        }
    }
}
