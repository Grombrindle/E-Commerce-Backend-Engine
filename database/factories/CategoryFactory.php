<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class CategoryFactory extends Factory
{
    public function definition(): array
    {
        $name = $this->faker->unique()->words(2, true);
        return [
            'name'        => ucwords($name),
            'slug'        => Str::slug($name) . '-' . $this->faker->numberBetween(1, 999),
            'description' => $this->faker->sentence(),
            'is_active'   => $this->faker->boolean(90),
        ];
    }
}
