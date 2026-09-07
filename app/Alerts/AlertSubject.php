<?php

namespace App\Alerts;

use App\Enums\UserRole;
use App\Models\OperationalAlert;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;

/**
 * The bounded set of things an operational alert may point at, and the only place a stored subject
 * turns into a link. Alerts store a short key ("product", "sale"), never a class name, and the key
 * is chosen by the alert type rather than supplied by anyone, so no request value ever reaches a
 * model lookup.
 */
final class AlertSubject
{
    /** @var array<string, class-string> */
    public const TYPES = [
        'product' => Product::class,
        'sale' => Sale::class,
    ];

    public static function keyFor(string $class): string
    {
        $key = array_search($class, self::TYPES, true);

        if ($key === false) {
            throw new \InvalidArgumentException("{$class} is not an alert subject type.");
        }

        return $key;
    }

    /**
     * The destination for an alert, or null when there is nothing safe to link to. Every URL is
     * built from a route name and an integer id, so a link can never carry a scheme like
     * javascript: or data:, an external host, or anything an operator typed.
     */
    public static function url(OperationalAlert $alert, User $viewer): ?string
    {
        if (! self::exists($alert)) {
            return null;
        }

        return match ($alert->subject_type) {
            // Sales Representatives cannot open other people's Sales; they never receive these
            // alerts today, but the link must not out-run the domain's own authorization either.
            'sale' => $viewer->role === UserRole::SalesRep
                ? null
                : route('sales.show', $alert->subject_id),
            'product' => route('inventory.products.show', $alert->subject_id),
            default => null,
        };
    }

    /** Whether the subject row still exists, so a deleted subject renders without a broken link. */
    public static function exists(OperationalAlert $alert): bool
    {
        $class = self::TYPES[$alert->subject_type] ?? null;

        if ($class === null) {
            return false;
        }

        return $class::query()->whereKey($alert->subject_id)->exists();
    }
}
