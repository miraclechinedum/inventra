<?php

namespace App\Support;

/**
 * Presentation helper for the fixed-precision quantity columns.
 *
 * Stock, reorder levels and sold quantities are stored as `decimal:3`, so they arrive as strings
 * like "42.000". This trims them for display only — it never rounds, and nothing here is used for
 * arithmetic, which stays with bcmath in the domain layer.
 */
final class Quantity
{
    public static function trim(?string $value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }

        $trimmed = str_contains($value, '.')
            ? rtrim(rtrim($value, '0'), '.')
            : $value;

        return $trimmed === '' || $trimmed === '-' ? '0' : $trimmed;
    }
}
