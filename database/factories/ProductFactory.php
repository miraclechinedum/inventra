<?php

namespace Database\Factories;

use App\Enums\ProductUnit;
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
            'category_id' => ProductCategory::factory(),
            'name' => fake()->words(3, true),
            'sku' => strtoupper(fake()->unique()->bothify('SKU-####-??')),
            'description' => fake()->optional()->sentence(),
            'cost_price' => '1000.00',
            'selling_price' => '1500.00',
            'current_stock' => '10.000',
            'reorder_level' => '5.000',
            'unit' => ProductUnit::Piece,
            'is_active' => true,
            'created_by' => User::factory(),
        ];
    }
}
