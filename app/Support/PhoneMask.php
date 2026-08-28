<?php

namespace App\Support;

class PhoneMask
{
    public static function display(string $phone): string
    {
        if (preg_match('/^\+234[789]\d{9}$/D', $phone) !== 1) {
            return '********';
        }

        return mb_substr($phone, 0, 7).'***'.mb_substr($phone, -4);
    }
}
