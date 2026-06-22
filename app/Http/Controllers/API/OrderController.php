<?php

// ═══════════════════════════════════════════════════════════════════════
// BEFORE — Task 1 (Race) + Task 2 (No Throttle) + Task 3 (Sync)
// ═══════════════════════════════════════════════════════════════════════
//
// Bad store() — no transaction, no rate limit, sync calls:
//
//  public function storeBad(Request $request)
//  {
//      // ⚠ Task 2: No rate limiter — unlimited requests
//      $product = Product::find($request->product_id);
//      // ⚠ Task 1: TOCTOU — check and act are NOT atomic
//      if ($product->stock < $request->quantity) {
//          return response()->json(['error' => 'Insufficient stock'], 422);
//      }
//      $product->stock -= $request->quantity;
//      $product->save();
//      $order = Order::create([...]);
//      // ⚠ Task 3: All synchronous — user blocks for ~3s
//      $this->invoiceService->generate($order);
//      $this->sendOrderNotifications($order);
//      $this->analyticsService->recordSale($order);
//      return response()->json(['order' => $order], 201);
//  }
//
// ═══════════════════════════════════════════════════════════════════════
// AFTER (current code): OrderService with transaction + throttle:orders + async
// ═══════════════════════════════════════════════════════════════════════


namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\PlaceOrderRequest;
use App\Helpers\CacheHelper;
use App\Services\OrderService;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(protected OrderService $orderService)
    {
    }

    public function index(Request $request)
    {
        $orders = $this->orderService->getUserOrders(
            $request->user(),
            $request->per_page ?? 15
        );
        return $this->paginated($orders);
    }

    public function store(PlaceOrderRequest $request)
    {
        try {
            $order = $this->orderService->placeOrder($request->user(), $request->validated());

            CacheHelper::flush(['products']);

            return $this->created($order, 'Order placed successfully.');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function show(Request $request, int $id)
    {
        $order = $request->user()
            ->orders()
            ->with(['items.product:id,name,image_url', 'payment'])
            ->findOrFail($id);

        return $this->success($order);
    }

    public function cancel(Request $request, int $id)
    {
        $request->validate(['reason' => 'nullable|string|max:500']);

        try {
            $order = $this->orderService->cancelOrder(
                $request->user(),
                $id,
                $request->reason ?? ''
            );

            CacheHelper::flush(['products']);

            return $this->success($order, 'Order cancelled.');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }
}
