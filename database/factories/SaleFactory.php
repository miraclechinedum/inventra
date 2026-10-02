<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SaleStatus;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\User;
use App\Support\SaleNumber;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/** @extends Factory<Sale> */
class SaleFactory extends Factory
{
    protected $model = Sale::class;

    public function configure(): static
    {
        return $this->afterCreating(function (Sale $sale): void {
            $seller = $sale->seller()->firstOrFail();
            $attributes = ['sold_by_name_snapshot' => $seller->name];

            // A walk-in has no Customer to copy from, and the `walkIn()` state has already written
            // the counter-sale snapshots CreateSale would write. Only a registered sale takes its
            // buyer snapshots from the related Customer.
            if (! $sale->is_walk_in) {
                // Test data may be built for a Business other than the one in context.
                $customer = Customer::acrossBusinesses()->findOrFail($sale->customer_id);
                $attributes += [
                    'customer_code_snapshot' => $customer->customer_code,
                    'customer_name_snapshot' => $customer->full_name,
                    'customer_phone_snapshot' => $customer->phone,
                ];
            }

            // Test-only bypass: synchronize coherent snapshots after related factories exist without weakening Sale immutability.
            DB::table('sales')->where('id', $sale->id)->update($attributes);
            $sale->setRawAttributes(array_merge($sale->getAttributes(), $attributes), true);
        });
    }

    /** A counter sale with no registered customer, mirroring what CreateSale writes. */
    public function forBusiness(Business $business): static
    {
        return $this->state(fn (): array => ['business_id' => $business->getKey()]);
    }

    public function walkIn(): static
    {
        return $this->state(fn (): array => [
            'is_walk_in' => true,
            'customer_id' => null,
            'customer_code_snapshot' => Sale::WALK_IN_CODE,
            'customer_name_snapshot' => Sale::WALK_IN_NAME,
            'customer_phone_snapshot' => null,
        ]);
    }

    public function definition(): array
    {
        return [
            // A real random reference, as CreateSale assigns. No placeholder is needed now that
            // the number does not depend on the primary key.
            'business_id' => fn (): int => BusinessFactory::installationId(),
            'sale_number' => SaleNumber::generate(),
            // Customer and seller come from the sale's own Business: the composite keys refuse any other.
            'customer_id' => fn (array $attributes) => Customer::factory()->state(['business_id' => $attributes['business_id']]),
            'is_walk_in' => false,
            // Today in the business timezone, not UTC: `sale_date` is a trading day, and near
            // midnight in Africa/Lagos the two differ. `walkIn()` below covers the other identity.
            'sale_date' => CarbonImmutable::now(config('business.timezone'))->toDateString(),
            'customer_code_snapshot' => 'PENDING-CUSTOMER',
            'customer_name_snapshot' => 'Pending Customer',
            'customer_phone_snapshot' => '+2348000000000',
            'status' => SaleStatus::Completed,
            'payment_method' => PaymentMethod::Cash,
            'payment_status' => PaymentStatus::Paid,
            'subtotal' => '100.00',
            'discount_amount' => '0.00',
            'total_amount' => '100.00',
            'amount_paid' => '100.00',
            'balance_due' => '0.00',
            'sold_by' => fn (array $attributes) => User::factory()->state(['business_id' => $attributes['business_id']]),
            'sold_by_name_snapshot' => 'Pending Seller',
        ];
    }
}
