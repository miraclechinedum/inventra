<?php

namespace Database\Factories;

use App\Enums\ProductUnit;
use App\Models\Business;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Product> */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'business_id' => fn (): int => BusinessFactory::installationId(),
            // The category comes from the product's own Business: the composite key refuses any other.
            'category_id' => fn (array $attributes) => ProductCategory::factory()->state(['business_id' => $attributes['business_id']]),
            'name' => fake()->words(3, true),
            'sku' => strtoupper(fake()->unique()->bothify('SKU-####-??')),
            'description' => fake()->optional()->sentence(),
            'cost_price' => '1000.00',
            'selling_price' => '1500.00',
            'current_stock' => '10.000',
            'reorder_level' => '5.000',
            'unit' => ProductUnit::Piece,
            'is_active' => true,
            'created_by' => fn (array $attributes) => User::factory()->state(['business_id' => $attributes['business_id']]),
        ];
    }

    public function forBusiness(Business $business): static
    {
        return $this->state(fn (): array => ['business_id' => $business->getKey()]);
    }
}
