<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SaleStatus;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\User;
use App\Support\SaleNumber;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/** @extends Factory<Sale> */
class SaleFactory extends Factory
{
    protected $model = Sale::class;

    public function configure(): static
    {
        return $this->afterCreating(function (Sale $sale): void {
            $customer = $sale->customer()->firstOrFail();
            $seller = $sale->seller()->firstOrFail();
            $attributes = [
                'customer_code_snapshot' => $customer->customer_code,
                'customer_name_snapshot' => $customer->full_name,
                'customer_phone_snapshot' => $customer->phone,
                'sold_by_name_snapshot' => $seller->name,
            ];

            if (str_starts_with($sale->sale_number, 'PENDING-')) {
                $attributes['sale_number'] = SaleNumber::fromId($sale->id);
            }

            // Test-only bypass: synchronize coherent snapshots after related factories exist without weakening Sale immutability.
            DB::table('sales')->where('id', $sale->id)->update($attributes);
            $sale->setRawAttributes(array_merge($sale->getAttributes(), $attributes), true);
        });
    }

    public function definition(): array
    {
        return [
            'sale_number' => 'PENDING-'.fake()->unique()->regexify('[A-Za-z0-9]{20}'),
            'customer_id' => Customer::factory(),
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
            'sold_by' => User::factory(),
            'sold_by_name_snapshot' => 'Pending Seller',
        ];
    }
}
