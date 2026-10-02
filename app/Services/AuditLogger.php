<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Tenancy\CurrentBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use LogicException;

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
        // Sale aggregates a discount approval moves, alongside the discount request's own fields.
        // All are money or short free text; none carry personal data.
        'returned_amount', 'refunded_amount', 'refundable_credit',
        'requested_amount', 'reason', 'decision_note',
        // Server-generated random image filenames. They contain no client-supplied text.
        'image_path', 'photo_path', 'logo_path',
        'business_name', 'business_phone', 'business_email', 'business_address',
        'state', 'receipt_footer',
        // The remaining Business profile settings. Without these an Administrator could change the
        // currency, the business type, the tax number or the low-stock alert destination and the
        // audit row would record the event with an empty diff — evidence that something happened
        // but not what. All are short business configuration; none carry personal data, and the
        // alert number is the business's own published contact, not a customer's.
        'business_type', 'currency', 'tax_number', 'manager_alert_number',
        // Subscription transitions: the stable plan key only, never provider data.
        'plan_key',
    ];

    private const SAFE_METADATA = [
        'sku', 'adjustment_type', 'quantity_change', 'quantity_before', 'quantity_after', 'reason', 'item_count',
        'delivery_id', 'sale_id', 'attempt',
        'purchase_id', 'purchase_number', 'supplier_id', 'supplier_code_snapshot', 'total_amount',
        'expense_id', 'expense_number', 'expense_category_id', 'category_code_snapshot', 'amount',
        'payment_method', 'incurred_at',
        'alert_type', 'alert_severity',
        // Distinguishes an automatic WhatsApp receipt from a staff-initiated one.
        'origin',
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

    /**
     * @param  bool  $explicitDiff  Pass true only when $oldValues and $newValues hold exactly the keys
     *                              that changed. Under that contract an allowlisted null means "this
     *                              field was deliberately cleared" and is preserved as JSON null.
     *                              Callers that hand over a whole model's attributes must leave this
     *                              false: there a null only means the column happens to be empty, and
     *                              recording it would bury the real change under nullable-column noise.
     */
    public function record(
        string $action,
        Model $auditable,
        ?User $actor,
        array $oldValues = [],
        array $newValues = [],
        array $metadata = [],
        bool $explicitDiff = false,
    ): void {
        $request = app()->bound('request') ? request() : null;
        $log = new AuditLog;
        $log->business_id = $this->owningBusiness($auditable, $actor);
        $log->actor_id = $actor?->getKey();
        $log->actor_name_snapshot = mb_substr($actor?->name ?? self::SYSTEM_ACTOR, 0, 120);
        $log->actor_role_snapshot = $actor?->role?->value;
        $log->action = $action;
        $log->auditable_type = $auditable->getMorphClass();
        $log->auditable_id = $auditable->getKey();
        $log->subject_label_snapshot = $this->subjectLabel($auditable);
        $safeFields = $action === 'expense_recorded' ? self::EXPENSE_SAFE_FIELDS : self::SAFE_FIELDS;
        $log->old_values = $this->sanitize($oldValues, $safeFields, $explicitDiff);
        $log->new_values = $this->sanitize($newValues, $safeFields, $explicitDiff);
        $log->metadata = $this->sanitize($metadata, self::SAFE_METADATA);
        $log->ip_address = $request instanceof Request ? $request->ip() : null;
        $log->user_agent = $request instanceof Request ? mb_substr((string) $request->userAgent(), 0, 512) : null;
        $log->save();
    }

    /**
     * The Business this evidence belongs to, from the record it describes wherever that record is
     * tenant-owned, then the actor, then the Business a legitimate system action established. Every
     * source present must agree: evidence that points two ways is refused, not filed under one.
     */
    private function owningBusiness(Model $auditable, ?User $actor): int
    {
        $current = app(CurrentBusiness::class);

        $sources = array_filter([
            'record' => array_key_exists('business_id', $auditable->getAttributes()) ? $auditable->getAttribute('business_id') : null,
            'actor' => $actor?->business_id,
            'context' => $current->has() ? $current->id() : null,
        ], static fn (mixed $id): bool => $id !== null);

        if ($sources === []) {
            throw new LogicException('An audit record needs a business: none could be established.');
        }

        if (count(array_unique(array_map('intval', $sources))) > 1) {
            throw new LogicException('An audit record cannot belong to more than one business.');
        }

        return (int) reset($sources);
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

    /**
     * Copies across only allowlisted keys the caller actually supplied, and only scalar values.
     * Arrays, objects and anything else stay out of the log entirely. A null survives only under
     * $preserveNulls, so an absent key and a deliberately cleared one remain distinguishable
     * without inventing a display sentinel.
     */
    private function sanitize(array $values, array $allowedKeys, bool $preserveNulls = false): ?array
    {
        $safe = [];

        foreach ($allowedKeys as $key) {
            if (! array_key_exists($key, $values)) {
                continue;
            }

            $value = $values[$key];

            if (is_string($value)) {
                $safe[$key] = mb_substr($value, 0, 500);
            } elseif (is_int($value) || is_float($value) || is_bool($value)) {
                $safe[$key] = $value;
            } elseif ($value === null && $preserveNulls) {
                $safe[$key] = null;
            }
        }

        return $safe === [] ? null : $safe;
    }
}
