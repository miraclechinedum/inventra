<?php

namespace App\Support;

use InvalidArgumentException;

final class Money
{
    public static function round(string $amount): string
    {
        if (! preg_match('/^-?\d+(?:\.\d+)?$/', $amount)) {
            throw new InvalidArgumentException('Money rounding requires a canonical decimal string.');
        }

        $roundingIncrement = str_starts_with($amount, '-') ? '-0.005' : '0.005';

        return bcadd($amount, $roundingIncrement, 2);
    }

    public static function format(string $amount): string
    {
        if (! preg_match('/^(?<sign>-?)(?<whole>\d+)(?:\.(?<fraction>\d{1,2}))?$/', $amount, $matches)) {
            throw new InvalidArgumentException('Money must be a canonical decimal string with at most two fractional digits.');
        }

        $whole = ltrim($matches['whole'], '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = str_pad($matches['fraction'] ?? '', 2, '0');

        $grouped = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole);

        return $matches['sign'].$grouped.'.'.$fraction;
    }

    /**
     * The same amount with a whole-naira value's trailing `.00` dropped: ₦84,000 rather than
     * ₦84,000.00.
     *
     * For dense listings only, where the column is scanned rather than read line by line. Receipts,
     * the PDF and any statement of what was actually charged keep `format()`, because two decimal
     * places are what a money document is expected to show. Kobo is never hidden — an amount with a
     * fractional part still prints it in full.
     */
    public static function compact(string $amount): string
    {
        $formatted = self::format($amount);

        return str_ends_with($formatted, '.00') ? mb_substr($formatted, 0, -3) : $formatted;
    }

    /**
     * A canonical decimal string, whatever the database handed back.
     *
     * `SUM()` over no rows returns null, and different drivers return integers, floats or strings
     * for the rest. Everything downstream here is bcmath on decimal strings, so values arrive
     * through this rather than being trusted to already be one.
     */
    public static function zero(?string $amount): string
    {
        if ($amount === null || trim($amount) === '') {
            return '0.00';
        }

        return self::round(number_format((float) $amount, 2, '.', ''));
    }

    /**
     * The short form a dashboard headline uses: ₦3.94M, ₦486K, ₦4,800.
     *
     * Display only. The full precision is kept in the value this is derived from — abbreviating is
     * the last thing that happens to a number, never something done before it is added up.
     *
     * Thresholds are chosen so the reader never has to decode an ambiguous figure: below a thousand
     * the amount is printed in full, and above it two significant decimals are kept only while they
     * say something (1.2M, not 1.20M).
     */
    public static function abbreviate(string $amount): string
    {
        $negative = str_starts_with($amount, '-');
        $value = ltrim($amount, '-');

        [$unit, $divisor] = match (true) {
            bccomp($value, '1000000000', 2) >= 0 => ['B', '1000000000'],
            bccomp($value, '1000000', 2) >= 0 => ['M', '1000000'],
            bccomp($value, '1000', 2) >= 0 => ['K', '1000'],
            default => ['', '1'],
        };

        if ($unit === '') {
            return ($negative ? '-' : '').self::compact($value);
        }

        // Two decimals, then trailing zeroes dropped: 3.94M, 1.2M, 486K.
        $scaled = rtrim(rtrim(bcdiv($value, $divisor, 2), '0'), '.');

        return ($negative ? '-' : '').$scaled.$unit;
    }
}
