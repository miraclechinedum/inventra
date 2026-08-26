<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\User;
use App\Support\CustomerCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Customer> */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function configure(): static
    {
        return $this->afterCreating(function (Customer $customer): void {
            if (str_starts_with($customer->customer_code, 'PENDING-')) {
                $customer->customer_code = CustomerCode::fromId($customer->id);
                $customer->saveQuietly();
            }
        });
    }

    public function definition(): array
    {
        $id = fake()->unique()->numberBetween(10000000, 99999999);

        return [
            'customer_code' => 'PENDING-'.fake()->unique()->regexify('[A-Za-z0-9]{20}'),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'phone' => '+23480'.$id,
            'email' => fake()->unique()->safeEmail(),
            'address' => fake()->streetAddress(),
            'city' => fake()->city(),
            'notes' => null,
            'is_active' => true,
            'whatsapp_opt_in' => false,
            'created_by' => User::factory(),
        ];
    }
}
