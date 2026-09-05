<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class AuditLogger
{
    private const EXPENSE_SAFE_FIELDS = [
        'expense_number', 'expense_category_id', 'category_code_snapshot', 'category_name_snapshot',
        'amount', 'payment_method', 'incurred_at', 'recorded_by', 'recorded_by_name_snapshot',
    ];

    private const SAFE_FIELDS = [
        'category_id', 'name', 'sku', 'description', 'cost_price', 'selling_price', 'current_stock', 'reorder_level', 'unit',
        'customer_code', 'first_name', 'last_name', 'phone', 'email', 'city', 'is_active', 'whatsapp_opt_in',
        'whatsapp_opt_in_at', 'whatsapp_opt_out_at',
        'sale_number', 'customer_id', 'subtotal', 'discount_amount', 'total_amount', 'amount_paid', 'balance_due',
        'payment_method', 'payment_status', 'status', 'voided_at', 'void_reason',
        'sale_id', 'customer_id', 'destination_phone', 'consent_checked_at', 'consent_opt_in_at_snapshot',
        'requested_at', 'provider_message_id', 'attempt', 'failure_code', 'failure_reason',
        'payment_id', 'payment_number', 'amount', 'payment_type', 'recorded_by', 'recorded_by_name_snapshot',
        'paid_at', 'cumulative_paid_after', 'balance_after', 'payment_status_after', 'resulting_payment_status',
        'supplier_code', 'contact_person', 'purchase_number', 'supplier_id',
        'supplier_code_snapshot', 'supplier_name_snapshot', 'supplier_phone_snapshot', 'reference_number',
        'received_by', 'received_by_name_snapshot', 'received_at',
        'category_code', 'expense_number', 'expense_category_id', 'category_code_snapshot',
        'category_name_snapshot', 'payee', 'recorded_by_name_snapshot', 'incurred_at',
        'return_id', 'return_number', 'refund_id', 'refund_number', 'merchandise_value', 'receivable_reduction',
        'returned_at', 'refunded_at', 'method',
    ];

    private const SAFE_METADATA = [
        'sku', 'adjustment_type', 'quantity_change', 'quantity_before', 'quantity_after', 'reason', 'item_count',
        'delivery_id', 'sale_id', 'attempt',
        'purchase_id', 'purchase_number', 'supplier_id', 'supplier_code_snapshot', 'total_amount',
        'expense_id', 'expense_number', 'expense_category_id', 'category_code_snapshot', 'amount',
        'payment_method', 'incurred_at',
    ];

    public function record(
        string $action,
        Model $auditable,
        ?User $actor,
        array $oldValues = [],
        array $newValues = [],
        array $metadata = [],
    ): void {
        $request = app()->bound('request') ? request() : null;
        $log = new AuditLog;
        $log->actor_id = $actor?->getKey();
        $log->action = $action;
        $log->auditable_type = $auditable->getMorphClass();
        $log->auditable_id = $auditable->getKey();
        $safeFields = $action === 'expense_recorded' ? self::EXPENSE_SAFE_FIELDS : self::SAFE_FIELDS;
        $log->old_values = $this->sanitize($oldValues, $safeFields);
        $log->new_values = $this->sanitize($newValues, $safeFields);
        $log->metadata = $this->sanitize($metadata, self::SAFE_METADATA);
        $log->ip_address = $request instanceof Request ? $request->ip() : null;
        $log->user_agent = $request instanceof Request ? mb_substr((string) $request->userAgent(), 0, 512) : null;
        $log->save();
    }

    private function sanitize(array $values, array $allowedKeys): ?array
    {
        $safe = [];

        foreach ($allowedKeys as $key) {
            $value = $values[$key] ?? null;

            if (is_string($value)) {
                $safe[$key] = mb_substr($value, 0, 500);
            } elseif (is_int($value) || is_float($value) || is_bool($value)) {
                $safe[$key] = $value;
            }
        }

        return $safe === [] ? null : $safe;
    }
}
