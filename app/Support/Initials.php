<?php

namespace App\Support;

use Illuminate\Support\Str;

/** Builds the short label the image placeholder shows when there is no photograph. */
class Initials
{
    public static function from(?string $name): string
    {
        $words = collect(preg_split('/\s+/', trim((string) $name)) ?: [])
            ->filter()
            ->map(fn (string $word): string => mb_substr($word, 0, 1))
            ->take(2);

        return $words->isEmpty() ? '—' : Str::upper($words->implode(''));
    }
}
