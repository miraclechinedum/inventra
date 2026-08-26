<?php

namespace App\Actions\Customer;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateCustomer
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(User $actor, Customer $customer, array $data): Customer
    {
        $allowed = match ($actor->role) {
            UserRole::Admin, UserRole::Manager => ['first_name', 'last_name', 'phone', 'email', 'address', 'city', 'notes'],
            UserRole::SalesRep => ['email', 'city'],
            default => [],
        };

        if (array_diff(array_keys($data), $allowed) !== []) {
            throw ValidationException::withMessages(['customer' => 'You cannot update one or more submitted customer fields.']);
        }

        return DB::transaction(function () use ($actor, $customer, $data): Customer {
            $locked = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            $old = $locked->only(['first_name', 'last_name', 'phone', 'email', 'city']);
            $oldPhone = $locked->phone;

            foreach (['first_name', 'last_name', 'phone', 'email', 'address', 'city', 'notes'] as $field) {
                if (array_key_exists($field, $data)) {
                    $locked->{$field} = $data[$field];
                }
            }

            $phoneChanged = array_key_exists('phone', $data) && $data['phone'] !== $oldPhone;

            if ($phoneChanged) {
                $locked->whatsapp_opt_in = false;
                $locked->whatsapp_opt_in_at = null;
                $locked->whatsapp_opt_out_at = null;
            }

            $locked->updated_by = $actor->id;
            $locked->save();

            $this->audit->record('customer_updated', $locked, $actor, $old, $locked->getAttributes());

            if ($phoneChanged) {
                $this->audit->record(
                    'customer_whatsapp_consent_reset',
                    $locked,
                    $actor,
                    metadata: ['reason' => 'phone_changed'],
                );
            }

            return $locked;
        });
    }
}
