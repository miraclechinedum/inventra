<?php

namespace App\Alerts;

use App\Enums\OperationalAlertSeverity;
use App\Enums\OperationalAlertStatus;
use App\Enums\OperationalAlertType;
use Illuminate\Http\Request;

/**
 * Query-string filters for the notification list. Every value is narrowed to a bounded scalar and
 * then checked against a fixed allowlist, so array-shaped or unknown input is discarded rather than
 * coerced into SQL. Unknown values fall back to "no filter" consistently.
 */
final readonly class AlertFilters
{
    public function __construct(
        public string $status,
        public string $readState,
        public string $severity,
        public string $type,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            self::allowed($request, 'status', OperationalAlertStatus::values()),
            self::allowed($request, 'read', ['unread', 'read', 'acknowledged']),
            self::allowed($request, 'severity', OperationalAlertSeverity::values()),
            self::allowed($request, 'type', OperationalAlertType::values()),
        );
    }

    /** @return array<string, string> */
    public function query(): array
    {
        return array_filter([
            'status' => $this->status, 'read' => $this->readState,
            'severity' => $this->severity, 'type' => $this->type,
        ], static fn (string $value): bool => $value !== '');
    }

    public function isActive(): bool
    {
        return $this->query() !== [];
    }

    /** @param  list<string>  $allowed */
    private static function allowed(Request $request, string $key, array $allowed): string
    {
        $value = $request->query($key);

        return is_string($value) && in_array($value, $allowed, true) ? $value : '';
    }
}
