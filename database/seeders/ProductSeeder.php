<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $electronics = Category::where('slug', 'electronics')->first();
        $clothing    = Category::where('slug', 'clothing-apparel')->first();
        $sports      = Category::where('slug', 'sports-fitness')->first();

        $products = [

            ['category_id' => $electronics?->id, 'name' => 'iPhone 15 Pro',       'price' => 999.99,  'compare_price' => 1099.99, 'sku' => 'ELEC-IPHONE15PRO', 'stock' => 50],
            ['category_id' => $electronics?->id, 'name' => 'Samsung Galaxy S24',  'price' => 849.99,  'compare_price' => 949.99,  'sku' => 'ELEC-SGS24',       'stock' => 45],
            ['category_id' => $electronics?->id, 'name' => 'MacBook Pro 14"',     'price' => 1999.99, 'compare_price' => null,    'sku' => 'ELEC-MBP14',       'stock' => 20],
            ['category_id' => $electronics?->id, 'name' => 'Sony WH-1000XM5',     'price' => 349.99,  'compare_price' => 399.99,  'sku' => 'ELEC-SONYWH5',     'stock' => 8],   
            ['category_id' => $electronics?->id, 'name' => 'iPad Air 5th Gen',    'price' => 599.99,  'compare_price' => null,    'sku' => 'ELEC-IPADAIR5',    'stock' => 35],

            ['category_id' => $clothing?->id,    'name' => 'Classic White Tee',   'price' => 29.99,   'compare_price' => 39.99,   'sku' => 'CLTH-WHT-TEE',     'stock' => 200],
            ['category_id' => $clothing?->id,    'name' => 'Slim Fit Jeans',      'price' => 79.99,   'compare_price' => 99.99,   'sku' => 'CLTH-SLIM-JNS',    'stock' => 150],
            ['category_id' => $clothing?->id,    'name' => 'Running Sneakers',    'price' => 119.99,  'compare_price' => 149.99,  'sku' => 'CLTH-RUN-SNK',     'stock' => 75],
            ['category_id' => $clothing?->id,    'name' => 'Winter Parka',        'price' => 199.99,  'compare_price' => null,    'sku' => 'CLTH-WIN-PRK',     'stock' => 0],   

            ['category_id' => $sports?->id,      'name' => 'Yoga Mat Pro',        'price' => 59.99,   'compare_price' => 79.99,   'sku' => 'SPRT-YOGA-MAT',    'stock' => 100],
            ['category_id' => $sports?->id,      'name' => 'Dumbbells Set 20kg',  'price' => 89.99,   'compare_price' => null,    'sku' => 'SPRT-DMBL-20',     'stock' => 30],
            ['category_id' => $sports?->id,      'name' => 'Protein Powder 2kg',  'price' => 49.99,   'compare_price' => 59.99,   'sku' => 'SPRT-PROT-2KG',    'stock' => 5],   
        ];

        foreach ($products as $p) {
            if (!$p['category_id']) continue;

            $product = Product::create([
                'category_id'   => $p['category_id'],
                'name'          => $p['name'],
                'slug'          => Str::slug($p['name']) . '-' . substr(uniqid(), -4),
                'description'   => "High quality {$p['name']}. Premium product with excellent features.",
                'price'         => $p['price'],
                'compare_price' => $p['compare_price'] ?? null,
                'sku'           => $p['sku'],
                'image_url'     => 'https://picsum.photos/seed/' . Str::slug($p['name']) . '/800/600',
                'is_active'     => true,
            ]);

            Inventory::create([
                'product_id'          => $product->id,
                'quantity'            => $p['stock'],
                'reserved_quantity'   => 0,
                'low_stock_threshold' => 10,
            ]);
        }

        Product::factory()->count(30)->create()->each(function ($product) {
            Inventory::create([
                'product_id'          => $product->id,
                'quantity'            => rand(0, 500),
                'reserved_quantity'   => 0,
                'low_stock_threshold' => 10,
            ]);
        });

        $this->command->info('✅ Products seeded: ' . (count($products) + 30));
    }
}
