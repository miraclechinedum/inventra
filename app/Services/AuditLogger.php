<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AuditLogger
{
    private const EXPENSE_SAFE_FIELDS = [
        'expense_number', 'expense_category_id', 'category_code_snapshot', 'category_name_snapshot',
        'amount', 'payment_method', 'incurred_at', 'recorded_by', 'recorded_by_name_snapshot',
    ];

    private const SAFE_FIELDS = [
        'category_id', 'name', 'sku', 'description', 'cost_price', 'selling_price', 'current_stock', 'reorder_level', 'unit',
        'customer_code', 'first_name', 'last_name', 'phone', 'email', 'city', 'is_active', 'whatsapp_opt_in',
        'role',
        'whatsapp_opt_in_at', 'whatsapp_opt_out_at',
        'sale_number', 'customer_id', 'subtotal', 'discount_amount', 'total_amount', 'amount_paid', 'balance_due',
        'payment_method', 'payment_status', 'status', 'voided_at', 'void_reason',
        'sale_id', 'destination_phone', 'consent_checked_at', 'consent_opt_in_at_snapshot',
        'requested_at', 'provider_message_id', 'attempt', 'failure_code', 'failure_reason',
        'payment_id', 'payment_number', 'amount', 'payment_type', 'recorded_by', 'recorded_by_name_snapshot',
        'paid_at', 'cumulative_paid_after', 'balance_after', 'payment_status_after', 'resulting_payment_status',
        'supplier_code', 'contact_person', 'purchase_number', 'supplier_id',
        'supplier_code_snapshot', 'supplier_name_snapshot', 'supplier_phone_snapshot', 'reference_number',
        'received_by', 'received_by_name_snapshot', 'received_at',
        'category_code', 'expense_number', 'expense_category_id', 'category_code_snapshot',
        'category_name_snapshot', 'payee', 'incurred_at',
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

    /**
     * Immutable business identifiers, most specific first. Paired with a display name below they
     * form a subject label that survives the subject row being renamed or removed, and that the
     * audit index can search without reaching into JSON columns.
     */
    private const SUBJECT_CODES = [
        'sale_number', 'payment_number', 'return_number', 'refund_number', 'expense_number',
        'purchase_number', 'supplier_code', 'customer_code', 'category_code', 'sku',
    ];

    /** Attribution used when no authenticated User initiated the mutation. */
    public const SYSTEM_ACTOR = 'System';

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
        $log->actor_name_snapshot = mb_substr($actor?->name ?? self::SYSTEM_ACTOR, 0, 120);
        $log->actor_role_snapshot = $actor?->role?->value;
        $log->action = $action;
        $log->auditable_type = $auditable->getMorphClass();
        $log->auditable_id = $auditable->getKey();
        $log->subject_label_snapshot = $this->subjectLabel($auditable);
        $safeFields = $action === 'expense_recorded' ? self::EXPENSE_SAFE_FIELDS : self::SAFE_FIELDS;
        $log->old_values = $this->sanitize($oldValues, $safeFields);
        $log->new_values = $this->sanitize($newValues, $safeFields);
        $log->metadata = $this->sanitize($metadata, self::SAFE_METADATA);
        $log->ip_address = $request instanceof Request ? $request->ip() : null;
        $log->user_agent = $request instanceof Request ? mb_substr((string) $request->userAgent(), 0, 512) : null;
        $log->save();
    }

    private function subjectLabel(Model $auditable): string
    {
        $attributes = $auditable->getAttributes();
        $parts = array_filter([$this->subjectCode($attributes), $this->subjectName($attributes)]);

        if ($parts !== []) {
            return mb_substr(implode(' · ', $parts), 0, 191);
        }

        return mb_substr(Str::headline(class_basename($auditable)).' #'.$auditable->getKey(), 0, 191);
    }

    /** @param  array<string, mixed>  $attributes */
    private function subjectCode(array $attributes): ?string
    {
        foreach (self::SUBJECT_CODES as $attribute) {
            $value = $attributes[$attribute] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $attributes */
    private function subjectName(array $attributes): ?string
    {
        $name = $attributes['name'] ?? null;

        if (! is_string($name) || trim($name) === '') {
            $name = implode(' ', array_filter([
                is_string($attributes['first_name'] ?? null) ? trim($attributes['first_name']) : null,
                is_string($attributes['last_name'] ?? null) ? trim($attributes['last_name']) : null,
            ]));
        }

        return trim((string) $name) === '' ? null : trim((string) $name);
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
