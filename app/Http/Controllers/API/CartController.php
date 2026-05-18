<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cart\AddToCartRequest;
use App\Http\Requests\Cart\UpdateCartItemRequest;
use App\Services\CartService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * CartController — Authenticated cart management.
 *
 * @GET    /api/v1/cart             → index()
 * @POST   /api/v1/cart/items       → addItem()
 * @PUT    /api/v1/cart/items/{id}  → updateItem()
 * @DELETE /api/v1/cart/items/{id}  → removeItem()
 * @DELETE /api/v1/cart             → clear()
 * @GET    /api/v1/cart/summary     → summary()
 */
class CartController extends Controller
{
    public function __construct(protected CartService $cartService)
    {
    }

    /** Get cart contents. */
    public function index(Request $request)
    {
        $data = $this->cartService->getCart($request->user());
        return $this->success($data);
    }

    /** Add product to cart. */
    public function addItem(AddToCartRequest $request)
    {
        try {
            $item = $this->cartService->addItem(
                $request->user(),
                $request->product_id,
                $request->quantity
            );

            // Inventory reservation changed → flush product caches
            Cache::tags(['products'])->flush();

            return $this->created($item->load('product'), 'Item added to cart.');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /** Update item quantity. */
    public function updateItem(UpdateCartItemRequest $request, int $id)
    {
        try {
            $item = $this->cartService->updateItem($request->user(), $id, $request->quantity);

            // Quantity change affects reservation → flush product caches
            Cache::tags(['products'])->flush();

            return $this->success($item, 'Cart updated.');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /** Remove item from cart. */
    public function removeItem(Request $request, int $id)
    {
        $this->cartService->removeItem($request->user(), $id);

        // Reservation released → flush product caches
        Cache::tags(['products'])->flush();

        return $this->success(null, 'Item removed from cart.');
    }

    /** Clear all items. */
    public function clear(Request $request)
    {
        $this->cartService->clearCart($request->user());

        // All reservations released → flush product caches
        Cache::tags(['products'])->flush();

        return $this->success(null, 'Cart cleared.');
    }

    /** Cart totals summary. */
    public function summary(Request $request)
    {
        $data = $this->cartService->getCart($request->user());

        $subtotal = $data['subtotal'] ?? 0;
        $tax = round($subtotal * 0.15, 2);
        $shippingFee = $subtotal >= 100 ? 0 : 9.99;

        return $this->success([
            'item_count' => $data['item_count'] ?? 0,
            'subtotal' => $subtotal,
            'tax' => $tax,
            'shipping_fee' => $shippingFee,
            'total' => $subtotal + $tax + $shippingFee,
        ]);
    }
}