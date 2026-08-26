<?php

namespace App\Actions\Customer;

use App\Models\Customer;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\CustomerCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateCustomer
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(User $actor, array $data): Customer
    {
        return DB::transaction(function () use ($actor, $data): Customer {
            $customer = new Customer;
            $customer->customer_code = 'PENDING-'.Str::random(20);
            $this->assignProfile($customer, $data);
            $customer->is_active = true;
            $customer->whatsapp_opt_in = (bool) ($data['whatsapp_opt_in'] ?? false);
            $customer->whatsapp_opt_in_at = $customer->whatsapp_opt_in ? now() : null;
            $customer->created_by = $actor->id;
            $customer->save();

            $customer->customer_code = CustomerCode::fromId($customer->id);
            $customer->save();

            $this->audit->record('customer_created', $customer, $actor, newValues: $customer->getAttributes());

            if ($customer->whatsapp_opt_in) {
                $this->audit->record('customer_whatsapp_opted_in', $customer, $actor, newValues: [
                    'whatsapp_opt_in' => true,
                    'whatsapp_opt_in_at' => $customer->whatsapp_opt_in_at?->toISOString(),
                ]);
            }

            return $customer;
        });
    }

    private function assignProfile(Customer $customer, array $data): void
    {
        $customer->first_name = $data['first_name'];
        $customer->last_name = $data['last_name'] ?? null;
        $customer->phone = $data['phone'];
        $customer->email = $data['email'] ?? null;
        $customer->address = $data['address'] ?? null;
        $customer->city = $data['city'] ?? null;
        $customer->notes = $data['notes'] ?? null;
    }
}
