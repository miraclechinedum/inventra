<?php

namespace App\Support;

/**
 * Presentation helper for the fixed-precision quantity columns.
 *
 * Stock, reorder levels and sold quantities are stored as `decimal:3`, so they arrive as strings
 * like "42.000". `trim` is for display only — it never rounds — and `isWhole` answers in bcmath, so
 * nothing here ever compares quantities as floats.
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

        // "-0" reads as a mistake rather than a quantity; a zero has no sign worth showing.
        return $trimmed === '' || $trimmed === '-' || $trimmed === '-0' ? '0' : $trimmed;
    }

    /** Whether a fixed-precision quantity is a whole number: "3", "3.000" yes; "2.500" no. */
    public static function isWhole(string $value): bool
    {
        return bccomp($value, bcadd($value, '0', 0), 3) === 0;
    }
}
