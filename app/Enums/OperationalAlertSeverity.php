<?php

namespace App\Enums;

/**
 * Server-derived only. Severity describes how urgently an operator should look, never a financial
 * or legal judgement, and no request value can set it.
 */
enum OperationalAlertSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';

    /** Text label, so severity never depends on colour alone. */
    public function label(): string
    {
        return match ($this) {
            self::Info => 'Information',
            self::Warning => 'Warning',
            self::Critical => 'Critical',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Info => 'bg-slate-100 text-slate-700',
            self::Warning => 'bg-amber-100 text-amber-800',
            self::Critical => 'bg-red-100 text-red-700',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
