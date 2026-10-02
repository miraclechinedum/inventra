<?php

namespace App\Services;

use App\Models\SecurityEvent;
use App\Models\User;
use App\Tenancy\CurrentBusiness;
use Illuminate\Http\Request;
use LogicException;

class SecurityEventRecorder
{
    private const ALLOWED_METADATA_KEYS = ['channel', 'reason', 'route', 'from_role', 'to_role', 'changed_fields'];

    public function record(
        string $event,
        ?User $subject = null,
        array $metadata = [],
        ?User $actor = null,
    ): void {
        $request = app()->bound('request') ? request() : null;

        // forceFill: business_id is written by the server alone and is never mass-assignable.
        (new SecurityEvent)->forceFill([
            'business_id' => $this->owningBusiness($subject, $actor),
            'user_id' => $subject?->getKey(),
            'actor_id' => $actor?->getKey(),
            'subject_user_id' => $subject?->getKey(),
            'event' => $event,
            'ip_address' => $request instanceof Request ? $request->ip() : null,
            'user_agent' => $request instanceof Request
                ? mb_substr((string) $request->userAgent(), 0, 512)
                : null,
            'metadata' => $this->sanitizeMetadata($metadata),
        ])->save();
    }

    /**
     * The Business of the account the event concerns — its subject, else its actor — or null when no
     * account is known. A failed login for an identifier matching no one has no honest owner, so it
     * is never attributed by inference from what was typed. Every source present must agree.
     */
    private function owningBusiness(?User $subject, ?User $actor): ?int
    {
        $current = app(CurrentBusiness::class);

        $sources = array_filter([
            $subject?->business_id,
            $actor?->business_id,
            $current->has() && ($subject !== null || $actor !== null) ? $current->id() : null,
        ], static fn (mixed $id): bool => $id !== null);

        if (count(array_unique(array_map('intval', $sources))) > 1) {
            throw new LogicException('A security event cannot concern more than one business.');
        }

        return $sources === [] ? null : (int) reset($sources);
    }

    private function sanitizeMetadata(array $metadata): ?array
    {
        $sanitized = [];

        foreach (self::ALLOWED_METADATA_KEYS as $key) {
            $value = $metadata[$key] ?? null;

            if (is_string($value)) {
                $sanitized[$key] = mb_substr($value, 0, 255);
            } elseif (is_int($value) || is_float($value) || is_bool($value)) {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized === [] ? null : $sanitized;
    }
}
