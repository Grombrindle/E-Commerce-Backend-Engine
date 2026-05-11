<?php

namespace App\Services;

use App\Models\Inventory;
use App\Models\Product;
use App\Events\StockLow;
use Illuminate\Support\Facades\DB;

/**
 * InventoryService — Thread-safe stock management.
 *
 * Uses pessimistic locking (SELECT FOR UPDATE) to prevent race conditions
 * when thousands of concurrent requests decrement stock.
 */
class InventoryService
{
    /**
     * Decrement stock for a product within an active transaction.
     * MUST be called inside DB::transaction().
     *
     * @throws \RuntimeException on insufficient stock
     */
    public function decrementStock(int $productId, int $quantity): Inventory
    {
        // Pessimistic lock: prevents concurrent decrement of same row
        $inventory = Inventory::where('product_id', $productId)
            ->lockForUpdate()
            ->firstOrFail();

        $available = $inventory->quantity - $inventory->reserved_quantity;

        if ($available < $quantity) {
            throw new \RuntimeException(
                "Insufficient stock for product #{$productId}. Available: {$available}, Requested: {$quantity}."
            );
        }

        $inventory->decrement('quantity', $quantity);

        // Fire low stock event if threshold crossed
        if ($inventory->fresh()->isLowStock()) {
            event(new StockLow($inventory->product));
        }

        return $inventory;
    }

    /**
     * Restore stock (on order cancellation).
     */
    public function restoreStock(int $productId, int $quantity): void
    {
        DB::transaction(function () use ($productId, $quantity) {
            Inventory::where('product_id', $productId)
                ->lockForUpdate()
                ->firstOrFail()
                ->increment('quantity', $quantity);
        });
    }

    /**
     * Reserve stock (on order placed, before payment confirmed).
     */
    public function reserveStock(int $productId, int $quantity): void
    {
        Inventory::where('product_id', $productId)
            ->lockForUpdate()
            ->firstOrFail()
            ->increment('reserved_quantity', $quantity);
    }

    /**
     * Release reservation without fulfilling.
     */
    public function releaseReservation(int $productId, int $quantity): void
    {
        Inventory::where('product_id', $productId)
            ->lockForUpdate()
            ->firstOrFail()
            ->decrement('reserved_quantity', $quantity);
    }

    /**
     * Admin: Set absolute quantity.
     */
    public function setStock(int $productId, int $quantity, int $threshold): Inventory
    {
        return DB::transaction(function () use ($productId, $quantity, $threshold) {
            $inv = Inventory::where('product_id', $productId)->lockForUpdate()->firstOrFail();
            $inv->update([
                'quantity'            => $quantity,
                'low_stock_threshold' => $threshold,
            ]);
            return $inv->fresh();
        });
    }

    /**
     * Get low stock products.
     */
    public function getLowStockProducts(int $limit = 50)
    {
        return Inventory::with('product:id,name,sku,image_url')
            ->whereRaw('quantity - reserved_quantity <= low_stock_threshold')
            ->orderByRaw('quantity - reserved_quantity ASC')
            ->limit($limit)
            ->get();
    }
}
