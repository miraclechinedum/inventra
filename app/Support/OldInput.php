<?php

namespace App\Support;

final class OldInput
{
    public static function scalar(string $key, mixed $default = ''): string|int|float|bool|null
    {
        $safeDefault = is_scalar($default) || $default === null ? $default : '';
        $value = old($key, $safeDefault);

        return is_scalar($value) || $value === null ? $value : $safeDefault;
    }
}
