<?php

namespace App\Subscriptions;

use App\Enums\UserRole;

/**
 * Every capability or limit a plan can grant, and what a plan that says nothing about it means.
 * This is the whole vocabulary: code asks through App\Subscriptions\Entitlements, never by plan key.
 *
 * Role caps are separate allowances: an unused Sales Representative place can never become a
 * second Manager, or the reverse. The Administrator — the Business's provisioned owner — holds
 * no role place.
 */
enum Entitlement: string
{
    /** Managers that are not deactivated. NULL is unlimited. */
    case MaxManagers = 'max_managers';

    /** Sales Representatives that are not deactivated. NULL is unlimited. */
    case MaxSalesRepresentatives = 'max_sales_representatives';

    /** Active products; archived and deactivated ones hold no place. NULL is unlimited. */
    case MaxProducts = 'max_products';

    /** Connecting WhatsApp and sending automation messages. */
    case WhatsAppAutomation = 'whatsapp_automation';

    public function isLimit(): bool
    {
        return $this !== self::WhatsAppAutomation;
    }

    /** What a plan that omits this entitlement grants: no limit, and no feature. */
    public function default(): int|bool|null
    {
        return $this->isLimit() ? null : false;
    }

    /** The role place a staff account of $role takes, or null when the role takes none. */
    public static function forRole(UserRole $role): ?self
    {
        return match ($role) {
            UserRole::Manager => self::MaxManagers,
            UserRole::SalesRep => self::MaxSalesRepresentatives,
            UserRole::Admin => null,
        };
    }

    /** What the Administrator reads when the limit is reached. Never a count of anything else. */
    public function limitMessage(int $limit): string
    {
        return match ($this) {
            self::MaxManagers => "Your current plan supports {$limit} ".($limit === 1 ? 'Manager' : 'Managers').'.',
            self::MaxSalesRepresentatives => "Your current plan supports {$limit} ".($limit === 1 ? 'Sales Representative' : 'Sales Representatives').'.',
            self::MaxProducts => "Your current plan supports up to {$limit} ".($limit === 1 ? 'product' : 'products').'.',
            self::WhatsAppAutomation => 'WhatsApp automation is not available on your current plan.',
        };
    }
}
