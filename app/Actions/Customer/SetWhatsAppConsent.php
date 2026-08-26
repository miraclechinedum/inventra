<?php

namespace App\Actions\Customer;

use App\Models\Customer;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SetWhatsAppConsent
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(User $actor, Customer $customer, bool $optIn): Customer
    {
        return DB::transaction(function () use ($actor, $customer, $optIn): Customer {
            $locked = Customer::query()->lockForUpdate()->findOrFail($customer->id);

            if ($optIn && ! $locked->is_active) {
                throw ValidationException::withMessages(['customer' => 'WhatsApp consent cannot be changed for an inactive customer.']);
            }

            if (($optIn && $locked->whatsapp_opt_in)
                || (! $optIn && ! $locked->whatsapp_opt_in && $locked->whatsapp_opt_out_at !== null)) {
                return $locked;
            }

            $old = [
                'whatsapp_opt_in' => $locked->whatsapp_opt_in,
                'whatsapp_opt_in_at' => $locked->whatsapp_opt_in_at?->toISOString(),
                'whatsapp_opt_out_at' => $locked->whatsapp_opt_out_at?->toISOString(),
            ];
            $now = now();
            $locked->whatsapp_opt_in = $optIn;
            $locked->whatsapp_opt_in_at = $optIn ? $now : null;
            $locked->whatsapp_opt_out_at = $optIn ? null : $now;
            $locked->updated_by = $actor->id;
            $locked->save();
            $this->audit->record(
                $optIn ? 'customer_whatsapp_opted_in' : 'customer_whatsapp_opted_out',
                $locked,
                $actor,
                $old,
                [
                    'whatsapp_opt_in' => $locked->whatsapp_opt_in,
                    'whatsapp_opt_in_at' => $locked->whatsapp_opt_in_at?->toISOString(),
                    'whatsapp_opt_out_at' => $locked->whatsapp_opt_out_at?->toISOString(),
                ],
            );

            return $locked;
        });
    }
}
