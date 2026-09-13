<?php

namespace App\Support;

use App\Models\Customer;

/**
 * The single definition of "this Customer may receive a WhatsApp receipt". Manual sends and
 * automatic dispatch both read it, so consent can never be interpreted one way by a staff member's
 * Send button and another way by the scheduler.
 */
class WhatsAppReceiptEligibility
{
    public static function permits(Customer $customer): bool
    {
        return $customer->is_active
            && $customer->whatsapp_opt_in
            && $customer->whatsapp_opt_out_at === null
            && $customer->whatsapp_opt_in_at !== null
            && CanonicalLoginIdentifier::normalizeNigerianPhone($customer->phone) === $customer->phone;
    }
}
