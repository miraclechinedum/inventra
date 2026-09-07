<?php

namespace App\Enums;

/**
 * Lifecycle of the underlying business condition, not of anyone reading it. An alert resolves only
 * when the condition it describes stops being true; no operator action can resolve one.
 */
enum OperationalAlertStatus: string
{
    case Active = 'active';
    case Resolved = 'resolved';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Resolved => 'Resolved',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
