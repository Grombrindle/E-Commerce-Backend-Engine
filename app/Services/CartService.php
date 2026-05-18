<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * CartService — Manages shopping cart lifecycle.
 */
class CartService
{
    public function __construct(
        protected InventoryService $inventoryService
    ) {
    }

    /**
     * Get or create a cart for the user.
     */
    public function getOrCreateCart(User $user): Cart
    {
        return Cart::firstOrCreate(
            ['user_id' => $user->id],
            ['expires_at' => now()->addDays(30)]
        );
    }

    /**
     * Add item to cart. If item exists, increments quantity.
     * Reserves stock after successful addition.
     *
     * @throws \RuntimeException
     */
    public function addItem(User $user, int $productId, int $quantity): CartItem
    {
        $product = Product::active()->with('inventory')->findOrFail($productId);

        // Check against available stock (quantity - reserved)
        if (!$product->isAvailable($quantity)) {
            throw new \RuntimeException(
                "Product '{$product->name}' does not have enough stock."
            );
        }

        $cart = $this->getOrCreateCart($user);

        $existing = CartItem::where('cart_id', $cart->id)
            ->where('product_id', $productId)
            ->first();

        if ($existing) {
            $newQty = $existing->quantity + $quantity;
            if (!$product->isAvailable($newQty)) {
                throw new \RuntimeException(
                    "Cannot add {$quantity} more units. Only " . ($product->inventory->quantity - $product->inventory->reserved_quantity) . " available."
                );
            }

            // Update quantity and adjust reservation
            $item = DB::transaction(function () use ($existing, $quantity, $newQty) {
                // Reserve only the additional quantity
                $this->inventoryService->reserveStock($existing->product_id, $quantity);
                $existing->update(['quantity' => $newQty]);
                return $existing->fresh(['product']);
            });

            return $item;
        }

        // New item: create cart item and reserve stock atomically
        return DB::transaction(function () use ($cart, $productId, $quantity, $product) {
            $this->inventoryService->reserveStock($productId, $quantity);

            return CartItem::create([
                'cart_id' => $cart->id,
                'product_id' => $productId,
                'quantity' => $quantity,
                'price' => $product->price, // snapshot current price
            ]);
        });
    }

    /**
     * Update item quantity. Adjusts reservation accordingly.
     *
     * @throws \RuntimeException
     */
    public function updateItem(User $user, int $itemId, int $quantity): CartItem
    {
        $cart = $this->getOrCreateCart($user);
        $item = CartItem::where('cart_id', $cart->id)->findOrFail($itemId);

        // Check availability for the new quantity
        if (!$item->product->isAvailable($quantity)) {
            throw new \RuntimeException("Requested quantity not available.");
        }

        $oldQty = $item->quantity;

        DB::transaction(function () use ($item, $oldQty, $quantity) {
            $diff = $quantity - $oldQty;
            if ($diff > 0) {
                // Increasing quantity → reserve more
                $this->inventoryService->reserveStock($item->product_id, $diff);
            } elseif ($diff < 0) {
                // Decreasing quantity → release some
                $this->inventoryService->releaseReservation($item->product_id, abs($diff));
            }

            $item->update(['quantity' => $quantity]);
        });

        return $item->fresh(['product']);
    }

    /**
     * Remove single item and release its reservation.
     */
    public function removeItem(User $user, int $itemId): void
    {
        $cart = $this->getOrCreateCart($user);
        $item = CartItem::where('cart_id', $cart->id)->findOrFail($itemId);

        DB::transaction(function () use ($item) {
            $this->inventoryService->releaseReservation($item->product_id, $item->quantity);
            $item->delete();
        });
    }

    /**
     * Clear entire cart and release all reservations.
     */
    public function clearCart(User $user): void
    {
        $cart = Cart::where('user_id', $user->id)->first();
        if (!$cart)
            return;

        DB::transaction(function () use ($cart) {
            foreach ($cart->items as $item) {
                $this->inventoryService->releaseReservation($item->product_id, $item->quantity);
            }
            $cart->items()->delete();
        });
    }

    /**
     * Get cart with items and totals.
     */
    public function getCart(User $user): array
    {
        $cart = Cart::where('user_id', $user->id)
            ->with('items.product.inventory')
            ->first();

        if (!$cart || $cart->isEmpty()) {
            return [
                'items' => [],
                'subtotal' => 0,
                'item_count' => 0,
            ];
        }

        return [
            'cart' => $cart,
            'items' => $cart->items,
            'subtotal' => $cart->total,
            'item_count' => $cart->item_count,
        ];
    }

    /**
     * Validate all cart items are still available before checkout.
     *
     * @throws \RuntimeException
     */
    public function validateForCheckout(Cart $cart): void
    {
        foreach ($cart->activeItems as $item) {
            if (!$item->product) {
                throw new \RuntimeException("Product '{$item->product_id}' no longer exists.");
            }
            if (!$item->product->is_active) {
                throw new \RuntimeException("Product '{$item->product->name}' is no longer available.");
            }
            if (!$item->product->isAvailable($item->quantity)) {
                throw new \RuntimeException(
                    "Product '{$item->product->name}' has insufficient stock. Available: " . ($item->product->inventory->quantity - $item->product->inventory->reserved_quantity) . "."
                );
            }
        }
    }
}