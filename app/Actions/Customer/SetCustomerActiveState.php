<?php

namespace App\Actions\Customer;

use App\Models\Customer;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class SetCustomerActiveState
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(User $actor, Customer $customer, bool $active): Customer
    {
        return DB::transaction(function () use ($actor, $customer, $active): Customer {
            $locked = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            $old = ['is_active' => $locked->is_active];
            $locked->is_active = $active;
            $locked->updated_by = $actor->id;
            $locked->save();
            $this->audit->record($active ? 'customer_activated' : 'customer_deactivated', $locked, $actor, $old, ['is_active' => $active]);

            return $locked;
        });
    }
}
