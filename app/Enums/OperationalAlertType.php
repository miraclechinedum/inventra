<?php

namespace App\Enums;

use App\Models\Product;
use App\Models\Sale;

/**
 * The operational conditions Inventra raises persistent alerts for. Each one already existed as a
 * transient Dashboard condition; this enum pins the ones worth a lifecycle, and nothing here is
 * derived from user input. Adding a case means defining its condition, subject, recipients and
 * resolution rule, so the list stays deliberately short.
 */
enum OperationalAlertType: string
{
    case InventoryLowStock = 'inventory_low_stock';
    case SaleReceivableOutstanding = 'sale_receivable_outstanding';
    case SaleRefundableCredit = 'sale_refundable_credit';
    case DataIntegrityWarning = 'data_integrity_warning';

    public function label(): string
    {
        return match ($this) {
            self::InventoryLowStock => 'Low stock',
            self::SaleReceivableOutstanding => 'Outstanding balance',
            self::SaleRefundableCredit => 'Refundable credit',
            self::DataIntegrityWarning => 'Ledger integrity',
        };
    }

    /** The model class this alert's subject always is. Never resolved from a request value. */
    public function subjectClass(): string
    {
        return match ($this) {
            self::InventoryLowStock => Product::class,
            self::SaleReceivableOutstanding,
            self::SaleRefundableCredit,
            self::DataIntegrityWarning => Sale::class,
        };
    }

    /**
     * Roles that receive this alert. Sales Representatives are deliberately absent from every type
     * in this foundation: receivables, credit and integrity are business-wide financial facts, and
     * an alert list is the wrong place to widen what a representative can see. Their Dashboard
     * already shows their own outstanding Sales and the low-stock list.
     *
     * @return list<UserRole>
     */
    public function recipientRoles(): array
    {
        return match ($this) {
            self::InventoryLowStock,
            self::SaleReceivableOutstanding,
            self::SaleRefundableCredit => [UserRole::Admin, UserRole::Manager],
            // Integrity warnings describe internal ledger disagreement; Administrators only.
            self::DataIntegrityWarning => [UserRole::Admin],
        };
    }

    /**
     * Whether a role may see this alert type *right now*. recipientRoles() decides who an alert is
     * delivered to; this decides who may still read it, and the two are deliberately the same list
     * so a role change takes effect immediately. Delivery history is never rewritten — only what
     * the holder is currently entitled to see.
     */
    public function allowsRole(UserRole $role): bool
    {
        return in_array($role, $this->recipientRoles(), true);
    }

    /**
     * The alert types a role may currently read, as column values for a SQL filter. Empty for a
     * Sales Representative, which is what makes their inbox and badge empty rather than filtered
     * in PHP after loading rows they are not allowed to see.
     *
     * @return list<string>
     */
    public static function visibleToRole(UserRole $role): array
    {
        return array_values(array_map(
            static fn (self $case): string => $case->value,
            array_filter(self::cases(), static fn (self $case): bool => $case->allowsRole($role)),
        ));
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
