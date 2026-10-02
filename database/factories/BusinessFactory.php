<?php

namespace Database\Factories;

use App\Enums\BusinessStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Business;
use App\Models\BusinessSetting;
use App\Models\BusinessSubscription;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A complete tenant: the Business, its one settings row and its subscription, since the application
 * treats a Business without either as an incomplete installation.
 *
 * @extends Factory<Business>
 */
class BusinessFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'status' => BusinessStatus::Active,
        ];
    }

    /**
     * The fixture Business every factory row defaults to: the one the ownership migration derives
     * from the bootstrapped settings row. Test data only — runtime code never picks a Business this way.
     */
    public static function installationId(): int
    {
        return Business::query()->orderBy('id')->value('id') ?? Business::factory()->create()->getKey();
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => ['status' => BusinessStatus::Suspended]);
    }

    /** A Business whose provisioning never created its settings row. */
    public function withoutSettings(): static
    {
        return $this->afterCreating(fn (Business $business) => $business->settings()->delete());
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Business $business): void {
            (new BusinessSetting)->forceFill([
                'business_id' => $business->getKey(),
                'business_name' => $business->name,
                'currency' => 'NGN',
            ])->save();

            // The grant every pre-subscription Business received: tests that care about plans set
            // their own explicitly.
            (new BusinessSubscription)->forceFill([
                'business_id' => $business->getKey(),
                'plan_id' => Plan::byKey('legacy')->getKey(),
                'status' => SubscriptionStatus::Active,
            ])->save();
        });
    }
}
