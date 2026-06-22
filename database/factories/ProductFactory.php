<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ProductFactory extends Factory
{
    public function definition(): array
    {
        $name  = $this->faker->unique()->words(3, true);
        $price = $this->faker->randomFloat(2, 5, 2000);

        return [
            'category_id'   => Category::inRandomOrder()->value('id') ?? Category::factory(),
            'name'          => ucwords($name),
            'slug'          => Str::slug($name) . '-' . $this->faker->numberBetween(1000, 9999),
            'description'   => $this->faker->paragraphs(2, true),
            'price'         => $price,
            'compare_price' => $this->faker->boolean(40) ? $price * 1.2 : null,
            'sku'           => strtoupper($this->faker->bothify('??##-####')),
            'image_url'     => 'https://picsum.photos/seed/' . Str::random(8) . '/800/600',
            'is_active'     => $this->faker->boolean(85),
            'weight'        => $this->faker->randomFloat(2, 0.1, 50),
        ];
    }
}
