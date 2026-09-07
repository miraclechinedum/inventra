<?php

namespace App\Alerts;

use App\Enums\OperationalAlertSeverity;
use App\Enums\OperationalAlertType;
use App\Models\Product;
use App\Models\Sale;
use App\Reports\BusinessReports;
use App\Support\Money;

/**
 * Turns current domain truth into alert conditions. Every predicate here is the one the Dashboard
 * already uses, so a persistent alert and the Dashboard's transient counter can never disagree:
 *
 *   low stock    active Product, not archived, current_stock <= reorder_level
 *   receivable   completed Sale, balance_due > 0
 *   credit       completed Sale, refundable_credit > 0
 *   integrity    completed Sale whose aggregates disagree with its ledger
 *
 * Money and stock are compared with bccomp or in SQL. No decimal is ever cast to float to decide
 * whether an alert exists.
 */
class OperationalAlertEvaluator
{
    public function __construct(
        private readonly OperationalAlertProjector $projector,
        private readonly BusinessReports $reports,
    ) {}

    /* --------------------------------------------------------------------- inventory */

    /** Opens, refreshes or resolves the low-stock alert for one Product. */
    public function evaluateProduct(Product $product): void
    {
        $condition = $this->lowStockCondition($product);

        $condition === null
            ? $this->projector->resolve(OperationalAlertType::InventoryLowStock, 'product', (int) $product->getKey())
            : $this->projector->open($condition);
    }

    private function lowStockCondition(Product $product): ?AlertCondition
    {
        // An archived or deactivated Product is out of operational circulation, so its stock level
        // stops being something anyone should act on. Matches the Dashboard's active() scope.
        if ($product->trashed() || ! $product->is_active) {
            return null;
        }

        if (bccomp((string) $product->current_stock, (string) $product->reorder_level, 3) > 0) {
            return null;
        }

        $outOfStock = bccomp((string) $product->current_stock, '0', 3) <= 0;
        $label = $product->sku.' · '.$product->name;

        return new AlertCondition(
            type: OperationalAlertType::InventoryLowStock,
            subjectType: 'product',
            subjectId: (int) $product->getKey(),
            severity: $outOfStock ? OperationalAlertSeverity::Critical : OperationalAlertSeverity::Warning,
            subjectLabel: $label,
            title: $outOfStock ? 'Product out of stock' : 'Product at or below reorder level',
            message: $outOfStock
                ? $product->name.' is out of stock. Reorder level is '.$this->quantity($product->reorder_level).' '.$product->unit->value.'.'
                : $product->name.' has '.$this->quantity($product->current_stock).' '.$product->unit->value.' left, at or below its reorder level of '.$this->quantity($product->reorder_level).'.',
        );
    }

    /* ------------------------------------------------------------------------- sales */

    /** Opens, refreshes or resolves both Sale-level financial alerts for one Sale. */
    public function evaluateSale(Sale $sale): void
    {
        foreach ([$this->receivableCondition($sale), $this->refundableCreditCondition($sale)] as $index => $condition) {
            $type = $index === 0
                ? OperationalAlertType::SaleReceivableOutstanding
                : OperationalAlertType::SaleRefundableCredit;

            $condition === null
                ? $this->projector->resolve($type, 'sale', (int) $sale->getKey())
                : $this->projector->open($condition);
        }
    }

    private function receivableCondition(Sale $sale): ?AlertCondition
    {
        // Voiding a Sale removes it from the receivable ledger entirely, exactly as the Dashboard
        // treats it. balance_due is already return-adjusted by synchronizeReturnFinancials().
        if ($sale->status->value !== 'completed' || bccomp((string) $sale->balance_due, '0', 2) <= 0) {
            return null;
        }

        return new AlertCondition(
            type: OperationalAlertType::SaleReceivableOutstanding,
            subjectType: 'sale',
            subjectId: (int) $sale->getKey(),
            severity: OperationalAlertSeverity::Warning,
            subjectLabel: $sale->sale_number.' · '.$sale->customer_name_snapshot,
            title: 'Sale has an outstanding balance',
            // No aging language: Inventra has no agreed payment term, so calling this overdue would
            // assert a business rule that does not exist.
            message: $sale->sale_number.' is carrying an outstanding balance of ₦'.Money::format((string) $sale->balance_due).'.',
        );
    }

    private function refundableCreditCondition(Sale $sale): ?AlertCondition
    {
        if ($sale->status->value !== 'completed' || bccomp((string) $sale->refundable_credit, '0', 2) <= 0) {
            return null;
        }

        return new AlertCondition(
            type: OperationalAlertType::SaleRefundableCredit,
            subjectType: 'sale',
            subjectId: (int) $sale->getKey(),
            severity: OperationalAlertSeverity::Info,
            subjectLabel: $sale->sale_number.' · '.$sale->customer_name_snapshot,
            title: 'Sale holds refundable customer credit',
            message: $sale->sale_number.' is holding ₦'.Money::format((string) $sale->refundable_credit).' of credit owed back to the customer.',
        );
    }

    /* --------------------------------------------------------------------- integrity */

    /**
     * Ledger integrity has no mutation to hang off — a mismatch is precisely the case where a
     * business write did not leave the state it claimed — so it is detected by reconciliation only.
     */
    public function evaluateIntegrity(): void
    {
        $mismatched = $this->reports->ledgerIntegrityMismatchSaleIds();

        foreach (Sale::query()->whereKey($mismatched)->orderBy('id')->get() as $sale) {
            $this->projector->open(new AlertCondition(
                type: OperationalAlertType::DataIntegrityWarning,
                subjectType: 'sale',
                subjectId: (int) $sale->getKey(),
                severity: OperationalAlertSeverity::Critical,
                subjectLabel: $sale->sale_number.' · '.$sale->customer_name_snapshot,
                title: 'Sale aggregate and ledger inconsistency detected',
                // Deliberately free of SQL, column names, amounts and stack detail: this alert says
                // which Sale to look at and nothing about the shape of the internal disagreement.
                message: $sale->sale_number.' does not agree with its payment, return or refund ledger. No records were changed; this is a detection only.',
            ));
        }

        $this->projector->resolveMissing(OperationalAlertType::DataIntegrityWarning, 'sale', $mismatched);
    }

    private function quantity(string $value): string
    {
        return rtrim(rtrim((string) $value, '0'), '.') ?: '0';
    }
}
