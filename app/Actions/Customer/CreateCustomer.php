<?php

namespace App\Actions\Customer;

use App\Actions\WhatsAppAutomation\WhatsAppAutomationTriggers;
use App\Models\Customer;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\CustomerCode;
use App\Tenancy\CurrentBusiness;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateCustomer
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly WhatsAppAutomationTriggers $whatsapp,
        private readonly CurrentBusiness $currentBusiness,
    ) {}

    public function execute(User $actor, array $data): Customer
    {
        $business = $this->currentBusiness->forActor($actor);

        $customer = DB::transaction(function () use ($actor, $data, $business): Customer {
            $customer = new Customer;
            $customer->business_id = $business->getKey();
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

        // After commit, so a WhatsApp problem can never fail or roll back customer creation. The
        // trigger checks consent and the automation switch itself, and is keyed on the customer so
        // a retried request cannot produce a second greeting.
        $this->whatsapp->customerCreated($customer);

        return $customer;
    }

    private function assignProfile(Customer $customer, array $data): void
    {
        $customer->first_name = $data['first_name'];
        $customer->last_name = $data['last_name'] ?? null;
        $customer->phone = $data['phone'];
        // Null unless the customer keeps WhatsApp on a different line; see Customer::
        // effectiveWhatsAppPhone() for why the same number is never stored twice.
        $customer->whatsapp_phone = $data['whatsapp_phone'] ?? null;
        $customer->email = $data['email'] ?? null;
        $customer->tag = $data['tag'] ?? null;
        $customer->address = $data['address'] ?? null;
        $customer->city = $data['city'] ?? null;
        $customer->notes = $data['notes'] ?? null;
    }
}
