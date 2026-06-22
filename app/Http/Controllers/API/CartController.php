<?php

// ═══════════════════════════════════════════════════════════════════════
// BEFORE — Cart Task: No Cache Flush, No Reservation
// ═══════════════════════════════════════════════════════════════════════
//
// Bad addItem() — does NOT flush product cache or reserve stock:
//
//  public function addItemBad(AddToCartRequest $request)
//  {
//      try {
//          $item = $this->cartService->addItem(...);
//          // ⚠ NO CacheHelper::flush(['products']) — stale product lists
//          return $this->created($item->load('product'), 'Item added.');
//      } catch (RuntimeException $e) {
//          return $this->error($e->getMessage(), 422);
//      }
//  }
//
// ═══════════════════════════════════════════════════════════════════════
// AFTER (current code): CacheHelper::flush(['products']) after each mutation
// ═══════════════════════════════════════════════════════════════════════

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cart\AddToCartRequest;
use App\Http\Requests\Cart\UpdateCartItemRequest;
use App\Helpers\CacheHelper;
use App\Services\CartService;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function __construct(protected CartService $cartService)
    {
    }

    public function index(Request $request)
    {
        $data = $this->cartService->getCart($request->user());
        return $this->success($data);
    }

    public function addItem(AddToCartRequest $request)
    {
        try {
            $item = $this->cartService->addItem(
                $request->user(),
                $request->product_id,
                $request->quantity
            );

            CacheHelper::flush(['products']);

            return $this->created($item->load('product'), 'Item added to cart.');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function updateItem(UpdateCartItemRequest $request, int $id)
    {
        try {
            $item = $this->cartService->updateItem($request->user(), $id, $request->quantity);

            CacheHelper::flush(['products']);

            return $this->success($item, 'Cart updated.');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function removeItem(Request $request, int $id)
    {
        $this->cartService->removeItem($request->user(), $id);

        CacheHelper::flush(['products']);

        return $this->success(null, 'Item removed from cart.');
    }

    public function clear(Request $request)
    {
        $this->cartService->clearCart($request->user());

        CacheHelper::flush(['products']);

        return $this->success(null, 'Cart cleared.');
    }

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
