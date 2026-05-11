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
     *
     * @throws \RuntimeException
     */
    public function addItem(User $user, int $productId, int $quantity): CartItem
    {
        $product = Product::active()->with('inventory')->findOrFail($productId);

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
                    "Cannot add {$quantity} more units. Only {$product->inventory->available_quantity} available."
                );
            }
            $existing->update(['quantity' => $newQty]);
            return $existing->fresh(['product']);
        }

        return CartItem::create([
            'cart_id'    => $cart->id,
            'product_id' => $productId,
            'quantity'   => $quantity,
            'price'      => $product->price, // snapshot current price
        ]);
    }

    /**
     * Update item quantity.
     *
     * @throws \RuntimeException
     */
    public function updateItem(User $user, int $itemId, int $quantity): CartItem
    {
        $cart = $this->getOrCreateCart($user);
        $item = CartItem::where('cart_id', $cart->id)->findOrFail($itemId);

        if (!$item->product->isAvailable($quantity)) {
            throw new \RuntimeException("Requested quantity not available.");
        }

        $item->update(['quantity' => $quantity]);
        return $item->fresh(['product']);
    }

    /**
     * Remove single item.
     */
    public function removeItem(User $user, int $itemId): void
    {
        $cart = $this->getOrCreateCart($user);
        CartItem::where('cart_id', $cart->id)->where('id', $itemId)->delete();
    }

    /**
     * Clear entire cart.
     */
    public function clearCart(User $user): void
    {
        $cart = Cart::where('user_id', $user->id)->first();
        if ($cart) {
            $cart->items()->delete();
        }
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
                'items'       => [],
                'subtotal'    => 0,
                'item_count'  => 0,
            ];
        }

        return [
            'cart'        => $cart,
            'items'       => $cart->items,
            'subtotal'    => $cart->total,
            'item_count'  => $cart->item_count,
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
                    "Product '{$item->product->name}' has insufficient stock. Available: {$item->product->inventory->available_quantity}."
                );
            }
        }
    }
}
