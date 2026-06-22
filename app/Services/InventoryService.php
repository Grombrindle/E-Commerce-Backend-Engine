<?php

namespace App\Services;

use App\Exceptions\InsufficientStockException;
use App\Models\Inventory;
use App\Models\Product;
use App\Events\StockLow;
use Illuminate\Support\Facades\DB;

class InventoryService
{

    //  no lockForUpdate()
    //
    //  public function decrementStockBad(int $productId, int $quantity): void
    //  {
    //      $inventory = Inventory::where('product_id', $productId)->first();
    //      // ⚠ NO lockForUpdate() — concurrent requests both see stock=1
    //      if ($inventory->quantity < $quantity) {
    //          throw new RuntimeException('Insufficient stock');
    //      }
    //      // ⚠ Race window: both requests pass this line before either writes
    //      $inventory->quantity -= $quantity;
    //      $inventory->save();  // ⚠ Last write wins — oversell!
    //  }

    public function decrementStock(int $productId, int $quantity): Inventory
    {

        $inventory = Inventory::where('product_id', $productId)
            ->lockForUpdate()
            ->firstOrFail();

        $available = $inventory->quantity - $inventory->reserved_quantity;

        if ($available < $quantity) {
            throw new InsufficientStockException(
                "Insufficient stock for product #{$productId}. Available: {$available}, Requested: {$quantity}."
            );
        }

        $inventory->decrement('quantity', $quantity);

        if ($inventory->fresh()->isLowStock()) {
            event(new StockLow($inventory->product));
        }

        return $inventory;
    }

    public function restoreStock(int $productId, int $quantity): void
    {
        DB::transaction(function () use ($productId, $quantity) {
            Inventory::where('product_id', $productId)
                ->lockForUpdate()
                ->firstOrFail()
                ->increment('quantity', $quantity);
        });
    }

    public function reserveStock(int $productId, int $quantity): void
    {
        Inventory::where('product_id', $productId)
            ->lockForUpdate()
            ->firstOrFail()
            ->increment('reserved_quantity', $quantity);
    }

    public function releaseReservation(int $productId, int $quantity): void
    {
        Inventory::where('product_id', $productId)
            ->lockForUpdate()
            ->firstOrFail()
            ->decrement('reserved_quantity', $quantity);
    }

    public function setStock(int $productId, int $quantity, int $threshold): Inventory
    {
        return DB::transaction(function () use ($productId, $quantity, $threshold) {
            $inv = Inventory::where('product_id', $productId)->lockForUpdate()->firstOrFail();
            $inv->update([
                'quantity' => $quantity,
                'low_stock_threshold' => $threshold,
            ]);
            return $inv->fresh();
        });
    }

    public function getLowStockProducts(int $limit = 50)
    {
        return Inventory::with('product:id,name,sku,image_url')
            ->whereRaw('quantity - reserved_quantity <= low_stock_threshold')
            ->orderByRaw('quantity - reserved_quantity ASC')
            ->limit($limit)
            ->get();
    }
}
