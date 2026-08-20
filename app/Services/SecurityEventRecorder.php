<?php

namespace App\Services;

use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Http\Request;

class SecurityEventRecorder
{
    private const ALLOWED_METADATA_KEYS = ['channel', 'reason', 'route'];

    public function record(string $event, ?User $user = null, array $metadata = []): void
    {
        $request = app()->bound('request') ? request() : null;

        SecurityEvent::query()->create([
            'user_id' => $user?->getKey(),
            'event' => $event,
            'ip_address' => $request instanceof Request ? $request->ip() : null,
            'user_agent' => $request instanceof Request
                ? mb_substr((string) $request->userAgent(), 0, 512)
                : null,
            'metadata' => $this->sanitizeMetadata($metadata),
        ]);
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
