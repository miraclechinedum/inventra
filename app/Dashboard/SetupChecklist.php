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
 * checklist can never be satisfied by another Business's data.
 *
 * Progress counts the required steps only. WhatsApp is offered, never required: it neither adds to
 * nor holds back the count, a Business whose required steps are done is shown as set up, and the
 * checklist disappears once nothing at all is left to offer.
 */
class SetupChecklist
{
    public function __construct(private readonly BusinessSettings $settings) {}

    /**
     * Null when there is nothing to show.
     *
     * @return array{steps: list<array{key: string, label: string, done: bool, route: string, optional: bool}>, done: int, total: int, complete: bool}|null
     */
    public function for(User $user): ?array
    {
        $steps = $this->steps($user);

        if ($steps === null || collect($steps)->every(fn (array $step): bool => $step['done'])) {
            return null;
        }

        $required = collect($steps)->reject(fn (array $step): bool => $step['optional']);
        $done = $required->where('done', true)->count();

        return [
            'steps' => $steps,
            'done' => $done,
            'total' => $required->count(),
            'complete' => $done === $required->count(),
        ];
    }

    /** @return list<array{key: string, label: string, done: bool, route: string, optional: bool}>|null */
    private function steps(User $user): ?array
    {
        if ($user->role !== UserRole::Admin) {
            return null;
        }

        $profile = $this->settings->current();

        return [
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
    }
}
