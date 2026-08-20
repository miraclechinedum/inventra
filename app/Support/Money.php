<?php

namespace App\Support;

use InvalidArgumentException;

final class Money
{
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
}
