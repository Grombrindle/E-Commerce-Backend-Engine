<?php

/* ============================================================
 * ╔══════════════════════════════════════════════════════════════╗
 * ║  BEFORE — Task 1: Race Condition (The Problem)              ║
 * ╚══════════════════════════════════════════════════════════════╝
 *
 * The original code had NO LOCK between checking stock and
 * decrementing it. This allowed two concurrent users to both
 * see stock=1, both pass the check, and both decrement —
 * resulting in stock = -1 (oversell).
 *
 *          Bad code (no lock):
 *
 *          public function decrementStock(int $productId, int $quantity): void
 *          {
 *              $inventory = Inventory::where('product_id', $productId)->first();
 *
 *              // ⚠ NO LOCK: User A and User B both read stock=1 simultaneously!
 *              $available = $inventory->quantity - $inventory->reserved_quantity;
 *
 *              // ⚠ Both pass this check because both saw the same value
 *              if ($available < $quantity) {
 *                  throw new RuntimeException('Insufficient stock');
 *              }
 *
 *              // ⚠ NO TRANSACTION: Each decrement runs separately
 *              // User A sets quantity = 0
 *              // User B sets quantity = -1 (OVERSELL!)
 *              $inventory->quantity -= $quantity;
 *              $inventory->save();
 *          }
 *
 * What went wrong:
 *  - No lockForUpdate() → concurrent reads see stale stock
 *  - No DB::transaction() → partial failures leave data inconsistent
 *  - PHP arithmetic instead of atomic DB decrement → race window
 *  - Result: 2 orders placed for 1 remaining item, stock = -1
 *
 * ╔══════════════════════════════════════════════════════════════╗
 * ║  AFTER — Task 1: Pessimistic Locking (The Fix)               ║
 * ╚══════════════════════════════════════════════════════════════╝
 *
 *          ✅ lockForUpdate() issues: SELECT ... FOR UPDATE
 *          ✅ Blocks other transactions from reading this row
 *          ✅ Only ONE thread can hold this lock at a time
 *          ✅ decrement() is a single atomic SQL UPDATE
 *
 * To test both versions:
 *   1. Comment out the AFTER code below
 *   2. Uncomment the BEFORE code above
 *   3. Fire 10 concurrent order requests — see stock go negative
 *   4. Restore the AFTER code — see stock stay >= 0
 * ============================================================ */

namespace App\Services;

use App\Exceptions\InsufficientStockException;
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
            throw new InsufficientStockException(
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
