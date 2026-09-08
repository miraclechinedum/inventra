<?php

namespace App\Support;

/**
 * Flattens the current query string into `name => value` pairs a GET form can carry as hidden
 * inputs, so a control that re-submits the page (the rows-per-page selector) keeps every active
 * search, filter and sort instead of dropping them.
 *
 * Nested parameters keep their bracket notation (`status[0]`) so they arrive back as the same
 * array shape the listing already validates.
 */
class QueryInputs
{
    /**
     * @param  array<string, mixed>  $query
     * @param  array<int, string>  $except  top-level keys to drop (typically `page` and `per_page`)
     * @return array<string, string>
     */
    public static function hidden(array $query, array $except = []): array
    {
        $pairs = [];

        foreach ($query as $key => $value) {
            if (in_array((string) $key, $except, true)) {
                continue;
            }

            self::walk((string) $key, $value, $pairs);
        }

        return $pairs;
    }

    /** @param  array<string, string>  $pairs */
    private static function walk(string $name, mixed $value, array &$pairs): void
    {
        if (is_array($value)) {
            foreach ($value as $childKey => $childValue) {
                self::walk($name.'['.$childKey.']', $childValue, $pairs);
            }

            return;
        }

        // Objects and resources have no meaningful form representation; scalars and null do.
        if (is_scalar($value) || $value === null) {
            $pairs[$name] = (string) $value;
        }
    }
}
