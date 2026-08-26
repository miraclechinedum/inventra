<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class AuditLogger
{
    private const SAFE_FIELDS = [
        'category_id', 'name', 'sku', 'description', 'cost_price', 'selling_price', 'current_stock', 'reorder_level', 'unit',
        'customer_code', 'first_name', 'last_name', 'phone', 'email', 'city', 'is_active', 'whatsapp_opt_in',
        'whatsapp_opt_in_at', 'whatsapp_opt_out_at',
    ];

    private const SAFE_METADATA = ['sku', 'adjustment_type', 'quantity_change', 'quantity_before', 'quantity_after', 'reason'];

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
        $log->old_values = $this->sanitize($oldValues, self::SAFE_FIELDS);
        $log->new_values = $this->sanitize($newValues, self::SAFE_FIELDS);
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
