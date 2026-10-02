<?php

namespace App\Actions\WhatsAppAutomation;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Customer;
use App\Models\User;
use App\Support\CanonicalLoginIdentifier;
use Illuminate\Support\Collection;

/**
 * Who may be sent a WhatsApp message, and on what number.
 *
 * The customer rules here are the old `WhatsAppReceiptEligibility` rules, carried across
 * deliberately rather than rewritten. The receipt *feature* was replaced; the consent *reasoning*
 * was correct and the customer data behind it is real, so it is reused verbatim: active customer,
 * opted in, never opted out, an opt-in timestamp on record, and a destination number that survives
 * canonical normalisation.
 *
 * Staff are treated differently on purpose. A low-stock alert goes to a colleague about the
 * business's own stock — there is no customer-consent question to ask — so the staff rule is about
 * reachability and standing, not permission: an active staff member with a usable number.
 */
final class WhatsAppAutomationEligibility
{
    /**
     * May this customer be sent an automated message?
     *
     * Note the destination is resolved once, by the same accessor the message will actually be sent
     * to. Checking one number and sending to another is how a consent check silently stops meaning
     * anything.
     */
    public static function permitsCustomer(Customer $customer): bool
    {
        $destination = $customer->effectiveWhatsAppPhone();

        return $customer->is_active
            // Consent. Having a number is not permission to use it.
            && $customer->whatsapp_opt_in
            && $customer->whatsapp_opt_out_at === null
            && $customer->whatsapp_opt_in_at !== null
            && $destination !== null
            && CanonicalLoginIdentifier::normalizeNigerianPhone($destination) === $destination;
    }

    /**
     * The number a staff member can actually be reached on, or null. Only an active account
     * qualifies: someone whose access has been revoked should stop receiving operational alerts
     * immediately, not keep them until a list is manually tidied.
     */
    public static function staffDestination(User $user): ?string
    {
        if ($user->status !== UserStatus::Active) {
            return null;
        }

        $phone = $user->phone;

        if (! is_string($phone) || trim($phone) === '') {
            return null;
        }

        $normalized = CanonicalLoginIdentifier::normalizeNigerianPhone($phone);

        return $normalized === $phone ? $normalized : null;
    }

    /**
     * Staff who may be chosen as low-stock recipients: Admins and Managers with a usable number.
     * Sales Representatives are excluded because reordering is not their responsibility — the
     * Figma's own framing is "alert to manager".
     *
     * @return Collection<int, User>
     */
    public static function eligibleRecipients(): Collection
    {
        // The CurrentBusiness's own staff only: another business's managers are never offered.
        return User::query()->inCurrentBusiness()
            ->whereIn('role', [UserRole::Admin, UserRole::Manager])
            ->where('status', UserStatus::Active)
            ->orderBy('name')
            ->get()
            ->filter(fn (User $user): bool => self::staffDestination($user) !== null)
            ->values();
    }
}
