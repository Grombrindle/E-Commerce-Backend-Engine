<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name' => 'Electronics',       'description' => 'Smartphones, laptops, tablets and accessories'],
            ['name' => 'Clothing & Apparel','description' => 'Fashion for all genders and occasions'],
            ['name' => 'Home & Garden',     'description' => 'Furniture, decor, tools, and outdoor living'],
            ['name' => 'Sports & Fitness',  'description' => 'Equipment, clothing, and nutrition'],
            ['name' => 'Books & Media',     'description' => 'Books, e-books, music, and movies'],
            ['name' => 'Beauty & Personal Care', 'description' => 'Skincare, haircare, and cosmetics'],
            ['name' => 'Toys & Games',      'description' => 'Toys for all ages'],
            ['name' => 'Automotive',        'description' => 'Car accessories and maintenance'],
        ];

        foreach ($categories as $cat) {
            Category::create([
                'name'        => $cat['name'],
                'slug'        => Str::slug($cat['name']),
                'description' => $cat['description'],
                'is_active'   => true,
            ]);
        }

        $this->command->info('✅ Categories seeded: ' . count($categories));
    }
}
