<?php

// ═══════════════════════════════════════════════════════════════════════
// BEFORE — Task 7: No Optimistic Locking (Lost Updates)
// ═══════════════════════════════════════════════════════════════════════
//
// Bad decrementStock() — no version check, concurrent updates lost:
//
//  public function decrementStockBad(int $productId, int $quantity): void
//  {
//      $inventory = Inventory::where('product_id', $productId)->first();
//      // ⚠ No WHERE version = ? check — last writer wins
//      // Two concurrent updates: both read same version, both write
//      // Last write overwrites the first — stock mismatch!
//      $inventory->quantity -= $quantity;
//      $inventory->save();
//  }
//
// ═══════════════════════════════════════════════════════════════════════
// AFTER (current code): WHERE version = ? + retry with exponential backoff
// ═══════════════════════════════════════════════════════════════════════
namespace App\Services;

use App\Exceptions\InsufficientStockException;
use App\Exceptions\OptimisticLockException;
use App\Models\Inventory;
use App\Events\StockLow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OptimisticInventoryService
{

    private const MAX_RETRIES = 5;

    private const BASE_RETRY_DELAY_MS = 10;

    public function decrementStock(int $productId, int $quantity, int $maxRetries = self::MAX_RETRIES): Inventory
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                return $this->attemptDecrement($productId, $quantity);
            } catch (OptimisticLockException $e) {
                $lastException = $e;
                Log::warning("Optimistic lock collision on inventory #{$productId} (attempt {$attempt}/{$maxRetries}). Retrying...");

                if ($attempt < $maxRetries) {

                    $delayMs = self::BASE_RETRY_DELAY_MS * (1 << ($attempt - 1)); 
                    usleep($delayMs * 1000);
                }
            }
        }

        throw new \RuntimeException(
            "Optimistic lock failed for inventory #{$productId} after {$maxRetries} attempts. " .
            "Last error: {$lastException?->getMessage()}"
        );
    }

    protected function attemptDecrement(int $productId, int $quantity): Inventory
    {
        return DB::transaction(function () use ($productId, $quantity) {

            $inventory = Inventory::where('product_id', $productId)->firstOrFail();

            $available = $inventory->quantity - $inventory->reserved_quantity;
            $currentVersion = $inventory->version;

            if ($available < $quantity) {
                throw new InsufficientStockException(
                    "Insufficient stock for product #{$productId}. " .
                    "Available: {$available}, Requested: {$quantity}."
                );
            }

            $updated = Inventory::where('product_id', $productId)
                ->where('version', $currentVersion)  
                ->update([
                    'quantity' => DB::raw("quantity - {$quantity}"),
                    'version'  => DB::raw("version + 1"),
                ]);

            if ($updated === 0) {
                throw new OptimisticLockException(
                    "Inventory #{$productId} was modified by another process. Retrying..."
                );
            }

            $inventory = Inventory::where('product_id', $productId)->firstOrFail();

            if ($inventory->isLowStock()) {
                event(new StockLow($inventory->product));
            }

            return $inventory;
        });
    }

    public function restoreStock(int $productId, int $quantity): void
    {
        $this->retryUntilSuccess(function () use ($productId, $quantity) {
            $inventory = Inventory::where('product_id', $productId)->firstOrFail();
            $currentVersion = $inventory->version;

            $updated = Inventory::where('product_id', $productId)
                ->where('version', $currentVersion)
                ->update([
                    'quantity' => DB::raw("quantity + {$quantity}"),
                    'version'  => DB::raw("version + 1"),
                ]);

            if ($updated === 0) {
                throw new OptimisticLockException("Version mismatch on restore stock #{$productId}");
            }
        });
    }

    public function reserveStock(int $productId, int $quantity): void
    {
        $this->retryUntilSuccess(function () use ($productId, $quantity) {
            $inventory = Inventory::where('product_id', $productId)->firstOrFail();
            $currentVersion = $inventory->version;

            $updated = Inventory::where('product_id', $productId)
                ->where('version', $currentVersion)
                ->update([
                    'reserved_quantity' => DB::raw("reserved_quantity + {$quantity}"),
                    'version'           => DB::raw("version + 1"),
                ]);

            if ($updated === 0) {
                throw new OptimisticLockException("Version mismatch on reserve stock #{$productId}");
            }
        });
    }

    public function releaseReservation(int $productId, int $quantity): void
    {
        $this->retryUntilSuccess(function () use ($productId, $quantity) {
            $inventory = Inventory::where('product_id', $productId)->firstOrFail();
            $currentVersion = $inventory->version;

            $updated = Inventory::where('product_id', $productId)
                ->where('version', $currentVersion)
                ->update([
                    'reserved_quantity' => DB::raw("MAX(reserved_quantity - {$quantity}, 0)"),
                    'version'           => DB::raw("version + 1"),
                ]);

            if ($updated === 0) {
                throw new OptimisticLockException("Version mismatch on release reservation #{$productId}");
            }
        });
    }

    private function retryUntilSuccess(callable $callback, int $maxRetries = self::MAX_RETRIES): void
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $callback();
                return;
            } catch (OptimisticLockException $e) {
                $lastException = $e;
                if ($attempt < $maxRetries) {
                    $delayMs = self::BASE_RETRY_DELAY_MS * (1 << ($attempt - 1));
                    usleep($delayMs * 1000);
                }
            }
        }

        throw new \RuntimeException(
            "Optimistic lock failed after {$maxRetries} attempts. Last error: {$lastException?->getMessage()}"
        );
    }

}
