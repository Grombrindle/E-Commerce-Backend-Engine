<?php

namespace Database\Seeders;

use App\Models\Inventory;
use App\Models\Product;
use Illuminate\Database\Seeder;

class K6TestSeeder extends Seeder
{
    public function run(): void
    {

        $product = Product::find(1);

        if ($product) {
            Inventory::updateOrCreate(
                ['product_id' => $product->id],
                [
                    'quantity' => 3,
                    'reserved_quantity' => 0,
                    'low_stock_threshold' => 5,
                ]
            );
            $this->command->info("✅ Product #1 stock set to 3 (race condition test data ready)");
        } else {
            $this->command->warn("⚠️  Product #1 not found. Run ProductSeeder first.");
        }
    }
}
