<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Resolves the shared `sort` / `direction` query parameters against a per-listing allowlist.
 *
 * A client-supplied column name never reaches orderBy(). The caller passes a map of the sort keys
 * it is willing to expose to the real column expressions, and this returns one of those exact
 * expressions or the caller's default — so an unknown, array-shaped or injection-shaped `sort`
 * degrades to the list's normal ordering.
 */
class TableSort
{
    /**
     * @param  array<string, string|array<int, string>>  $allowed  sort key => column, or key => [column, ...tiebreakers]
     * @return array{key: string, direction: string, columns: array<int, string>}
     */
    public static function resolve(Request $request, array $allowed, string $default, string $defaultDirection = 'asc'): array
    {
        $requested = $request->query('sort');
        $key = is_string($requested) && array_key_exists($requested, $allowed) ? $requested : $default;

        // Only the requested key gets a requested direction. Falling back to the default key also
        // falls back to its natural direction, so a stray `direction=desc` cannot silently invert
        // a list the operator never asked to sort.
        $direction = $key === $requested
            ? (mb_strtolower((string) $request->query('direction')) === 'desc' ? 'desc' : 'asc')
            : $defaultDirection;

        return [
            'key' => $key,
            'direction' => $direction,
            'columns' => (array) ($allowed[$key] ?? $allowed[$default]),
        ];
    }
}
