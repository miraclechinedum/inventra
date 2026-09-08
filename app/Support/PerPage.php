<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Resolves the shared `per_page` query parameter for every record list.
 *
 * The value reaches a LIMIT clause, so it is never taken from the request as-is: anything that is
 * not one of the four offered sizes — an array, a negative number, `1000000`, a string, a null
 * byte — falls back to the default rather than failing, so a tampered URL degrades to the normal
 * list instead of an error page or an unbounded query.
 */
class PerPage
{
    /** The sizes the rows-per-page control offers, and the only sizes a request may ask for. */
    public const OPTIONS = [10, 25, 50, 100];

    public const DEFAULT = 10;

    public static function resolve(Request $request, int $default = self::DEFAULT): int
    {
        $requested = $request->query('per_page');

        if (is_string($requested) && ctype_digit($requested) && in_array((int) $requested, self::OPTIONS, true)) {
            return (int) $requested;
        }

        return in_array($default, self::OPTIONS, true) ? $default : self::DEFAULT;
    }
}
