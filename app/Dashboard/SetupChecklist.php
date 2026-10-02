<?php

namespace App\Dashboard;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Settings\BusinessSettings;

/**
 * The first-run checklist on an Administrator's dashboard.
 *
 * Each step is answered from the Business's own records at render time — there is no stored
 * checklist state to drift out of date — and every query is tenant-scoped, so a new Business's
 * checklist can never be satisfied by another Business's data. Once every step is done the
 * checklist is simply not shown. WhatsApp is offered, never required.
 */
class SetupChecklist
{
    public function __construct(private readonly BusinessSettings $settings) {}

    /** @return list<array{key: string, label: string, done: bool, route: string, optional: bool}>|null null when there is nothing to show */
    public function for(User $user): ?array
    {
        if ($user->role !== UserRole::Admin) {
            return null;
        }

        $profile = $this->settings->current();

        $steps = [
            ['key' => 'profile', 'label' => 'Complete your business profile', 'route' => 'settings.business.edit', 'optional' => false,
                'done' => filled($profile->business_phone) && filled($profile->business_address)],
            ['key' => 'product', 'label' => 'Add your first product', 'route' => 'inventory.products.create', 'optional' => false,
                'done' => Product::query()->withTrashed()->exists()],
            ['key' => 'customer', 'label' => 'Add your first customer', 'route' => 'customers.create', 'optional' => false,
                'done' => Customer::query()->exists()],
            ['key' => 'staff', 'label' => 'Invite your staff', 'route' => 'staff.create', 'optional' => false,
                'done' => User::query()->inCurrentBusiness()->whereKeyNot($user->getKey())->exists()],
            ['key' => 'whatsapp', 'label' => 'Connect WhatsApp', 'route' => 'whatsapp.automation.index', 'optional' => true,
                'done' => WhatsAppConnection::forCurrentBusiness()->isConnected()],
        ];

        return collect($steps)->every(fn (array $step): bool => $step['done']) ? null : $steps;
    }
}
