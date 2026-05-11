<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

class UserFactory extends Factory
{
    protected static ?string $password = null;

    public function definition(): array
    {
        return [
            'name'              => $this->faker->name(),
            'email'             => $this->faker->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password'          => static::$password ??= Hash::make('password'),
            'remember_token'    => \Illuminate\Support\Str::random(10),
            'role'              => 'customer',
            'phone'             => $this->faker->phoneNumber(),
            'address'           => [
                'street'  => $this->faker->streetAddress(),
                'city'    => $this->faker->city(),
                'country' => $this->faker->country(),
                'zip'     => $this->faker->postcode(),
            ],
        ];
    }

    public function admin(): static
    {
        return $this->state(['role' => 'admin']);
    }
}
