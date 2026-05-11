<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Admin user
        User::create([
            'name'     => 'Super Admin',
            'email'    => 'admin@ecommerce.test',
            'password' => Hash::make('password'),
            'role'     => 'admin',
            'phone'    => '+1234567890',
            'address'  => ['street' => '123 Admin St', 'city' => 'San Francisco', 'country' => 'US', 'zip' => '94105'],
        ]);

        // Test customer
        User::create([
            'name'     => 'John Customer',
            'email'    => 'customer@ecommerce.test',
            'password' => Hash::make('password'),
            'role'     => 'customer',
            'phone'    => '+0987654321',
            'address'  => ['street' => '456 Customer Ave', 'city' => 'New York', 'country' => 'US', 'zip' => '10001'],
        ]);

        // Additional fake customers
        User::factory()->count(18)->create();

        $this->command->info('✅ Users seeded (admin + customer + 18 fake users)');
    }
}
