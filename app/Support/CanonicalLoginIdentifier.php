<?php

namespace App\Support;

use Illuminate\Support\Str;

final readonly class CanonicalLoginIdentifier
{
    private function __construct(
        public string $type,
        public string $value,
    ) {}

    public static function from(string $identifier): ?self
    {
        $identifier = trim($identifier);

        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            return new self('email', Str::lower($identifier));
        }

        $phone = self::normalizeNigerianPhone($identifier);

        return $phone === null ? null : new self('phone', $phone);
    }

    public static function normalizeNigerianPhone(string $phone): ?string
    {
        $phone = trim($phone);

        if ($phone === '' || ! preg_match('/^\+?[0-9\s()\-]+$/', $phone)) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $phone);

        if (preg_match('/^0([789][0-9]{9})$/', (string) $digits, $matches)) {
            return '+234'.$matches[1];
        }

        if (preg_match('/^234([789][0-9]{9})$/', (string) $digits, $matches)) {
            return '+234'.$matches[1];
        }

        return null;
    }
}
