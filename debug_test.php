<?php

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;

$cat = Category::factory()->create(['is_active' => true]);

$active = Product::factory()->create([
    'category_id' => $cat->id,
    'is_active'   => true,
]);
$inactive = Product::factory()->create([
    'category_id' => $cat->id,
    'is_active'   => false,
]);

Inventory::create([
    'product_id' => $active->id,
    'quantity'   => 100,
    'reserved_quantity' => 0,
    'low_stock_threshold' => 10,
]);
Inventory::create([
    'product_id' => $inactive->id,
    'quantity'   => 100,
    'reserved_quantity' => 0,
    'low_stock_threshold' => 10,
]);

echo "Active product ID: {$active->id}, is_active: " . var_export($active->is_active, true) . PHP_EOL;
echo "Inactive product ID: {$inactive->id}, is_active: " . var_export($inactive->is_active, true) . PHP_EOL;

$raw = DB::table('products')->get();
foreach ($raw as $p) {
    echo "  DB: id={$p->id}, is_active=" . var_export($p->is_active, true) . PHP_EOL;
}

$results = Product::active()->get();
echo "Active scope returned " . $results->count() . " products, IDs: [" . $results->pluck('id')->implode(',') . "]" . PHP_EOL;

$ids = $results->pluck('id');
echo "Contains active? " . ($ids->contains($active->id) ? 'YES' : 'NO') . PHP_EOL;
echo "Contains inactive? " . ($ids->contains($inactive->id) ? 'YES' : 'NO') . PHP_EOL;

DB::enableQueryLog();
Product::active()->get();
$log = DB::getQueryLog();
echo "SQL: " . json_encode($log) . PHP_EOL;
