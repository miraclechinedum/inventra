<?php

namespace App\Actions\Customer;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\CanonicalLoginIdentifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateCustomer
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(User $actor, Customer $customer, array $data): Customer
    {
        // The new fields follow the existing division rather than widening it: identity and
        // contact details stay with Admin and Manager, and a Sales Rep's narrow set gains only the
        // tag — a descriptive label, not a way to reach or re-identify the customer.
        $allowed = match ($actor->role) {
            UserRole::Admin, UserRole::Manager => ['first_name', 'last_name', 'phone', 'whatsapp_phone', 'email', 'address', 'city', 'notes', 'tag'],
            UserRole::SalesRep => ['email', 'city', 'tag'],
            default => [],
        };

        if (array_diff(array_keys($data), $allowed) !== []) {
            throw ValidationException::withMessages(['customer' => 'You cannot update one or more submitted customer fields.']);
        }

        return DB::transaction(function () use ($actor, $customer, $data): Customer {
            $locked = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            $old = $locked->only(['first_name', 'last_name', 'phone', 'whatsapp_phone', 'email', 'city', 'tag']);
            $oldPhone = $locked->phone;
            $oldDestination = self::canonicalDestination($locked);

            foreach (['first_name', 'last_name', 'phone', 'whatsapp_phone', 'email', 'address', 'city', 'notes', 'tag'] as $field) {
                if (array_key_exists($field, $data)) {
                    $locked->{$field} = $data[$field];
                }
            }

            $phoneChanged = array_key_exists('phone', $data) && $data['phone'] !== $oldPhone;
            // Consent covers the number messages are actually sent to, which prefers whatsapp_phone.
            $destinationChanged = self::canonicalDestination($locked) !== $oldDestination;
            $consentReset = $phoneChanged || $destinationChanged;

            if ($consentReset) {
                $locked->whatsapp_opt_in = false;
                $locked->whatsapp_opt_in_at = null;
                $locked->whatsapp_opt_out_at = null;
            }

            $locked->updated_by = $actor->id;
            $locked->save();

            $this->audit->record('customer_updated', $locked, $actor, $old, $locked->getAttributes());

            if ($consentReset) {
                $this->audit->record(
                    'customer_whatsapp_consent_reset',
                    $locked,
                    $actor,
                    metadata: ['reason' => $phoneChanged ? 'phone_changed' : 'whatsapp_phone_changed'],
                );
            }

            return $locked;
        });
    }

    private static function canonicalDestination(Customer $customer): ?string
    {
        $destination = $customer->effectiveWhatsAppPhone();

        return $destination === null
            ? null
            : CanonicalLoginIdentifier::normalizeNigerianPhone($destination) ?? $destination;
    }
}
