<?php

namespace App\Actions\Business;

use App\Actions\WhatsAppAutomation\EnsureDefaultAutomations;
use App\Enums\BusinessStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Business;
use App\Models\BusinessSetting;
use App\Models\Plan;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SecurityEventRecorder;
use App\Subscriptions\SubscriptionLifecycle;
use App\Tenancy\CurrentBusiness;
use Illuminate\Support\Facades\DB;

/**
 * The one way a new tenant comes into existence. Public signup uses it, and so must any future
 * platform or command-line provisioning: nothing else creates a Business.
 *
 * In one transaction it creates the active Business, its single settings row, its first
 * Administrator, its default WhatsApp automations and its subscription — a trial on the configured
 * default plan, named by stable key — and records the provisioning. Any failure —
 * including losing a race for the owner's globally unique email or phone — rolls all of it back,
 * so there is never a Business without its owner or an owner without a Business.
 *
 * Everything is server-assigned. The input is identity only: nothing in it can choose a role, a
 * status or another Business, and nothing is copied from any existing Business. No external
 * service is involved.
 */
class ProvisionBusiness
{
    /** The only currency this installation supports; see BusinessSettings. */
    private const CURRENCY = 'NGN';

    public function __construct(
        private readonly EnsureDefaultAutomations $automations,
        private readonly AuditLogger $audit,
        private readonly SecurityEventRecorder $events,
        private readonly CurrentBusiness $tenancy,
        private readonly SubscriptionLifecycle $subscriptions,
    ) {}

    /**
     * @param  array{business_name: string, owner_name: string, email: string, phone: string|null, password: string}  $input
     * @return User the new Business's Administrator
     */
    public function execute(array $input): User
    {
        // Resolved before anything is written: an undefined default plan is a deployment error.
        $plan = Plan::byKey((string) config('plans.default'));

        return DB::transaction(function () use ($input, $plan): User {
            $business = new Business;
            $business->forceFill(['name' => $input['business_name'], 'status' => BusinessStatus::Active])->save();

            // Everything tenant-owned is written inside the new Business, so no ambient context
            // left by other work can be attached to it.
            return $this->tenancy->run($business, function () use ($business, $input, $plan): User {
                $settings = new BusinessSetting;
                $settings->forceFill([
                    'business_id' => $business->getKey(),
                    'business_name' => $input['business_name'],
                    'currency' => self::CURRENCY,
                ])->save();

                $owner = new User;
                $owner->business_id = $business->getKey();
                $owner->name = $input['owner_name'];
                $owner->email = $input['email'];
                $owner->phone = $input['phone'];
                $owner->password = $input['password'];
                $owner->role = UserRole::Admin;
                $owner->status = UserStatus::Active;
                // The owner chose this password; the optional quick PIN is still offered once.
                $owner->force_password_change = false;
                $owner->quick_pin_setup_completed = false;
                $owner->failed_login_attempts = 0;
                // A public signup proves its email before it operates the Business.
                $owner->email_verification_required = true;
                $owner->save();

                $this->automations->for($business);
                $this->subscriptions->startTrial($business, $plan, $owner);

                $this->events->record('business_provisioned', $owner, ['to_role' => UserRole::Admin->value], $owner);
                $this->audit->record('business_provisioned', $settings, $owner, newValues: [
                    'business_name' => $settings->business_name,
                    'currency' => $settings->currency,
                    'name' => $owner->name,
                    'email' => $owner->email,
                    'role' => UserRole::Admin->value,
                ]);

                return $owner;
            });
        });
    }
}
