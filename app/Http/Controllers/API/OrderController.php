<?php

/* ============================================================
 * ╔══════════════════════════════════════════════════════════════╗
 * ║  BEFORE — Task 1 + Task 2 + Task 3: The Problem             ║
 * ╚══════════════════════════════════════════════════════════════╝
 *
 * Task 1 (Race Condition): The original store() checked and
 * decremented stock DIRECTLY without any transaction or lock.
 * Two users could both see stock=1 and both decrement → oversell.
 *
 * Task 2 (No Throttling): There was NO rate limiting on orders.
 * 1000 simultaneous requests all executed at once → server crash.
 *
 * Task 3 (Synchronous Blocking): After creating the order, invoice
 * generation, email, and analytics ran SYNCHRONOUSLY in the
 * controller — user waited ~3.9 seconds for the response.
 *
 *          Bad code (all three problems combined):
 *
 *          // ⚠ Task 2: No rate limiter → unlimited requests pass through
 *          public function placeOrder(Request $request)
 *          {
 *              $product = Product::find($request->product_id);
 *
 *              // ⚠ Task 1: TOCTOU — check and act are NOT atomic
 *              if ($product->stock < $request->quantity) {
 *                  return response()->json(['error' => 'Insufficient stock'], 422);
 *              }
 *
 *              $product->stock -= $request->quantity;  // ⚠ Race window!
 *              $product->save();
 *
 *              $order = Order::create([...]);
 *
 *              // ⚠ Task 3: All synchronous — user waits for each
 *              $this->invoiceService->generate($order);       // ~1.5s
 *              $this->notificationService->sendEmail($order);  // ~1.0s
 *              $this->analyticsService->recordSale($order);    // ~0.5s
 *
 *              // ⚠ Total: ~3.9 seconds (user blocked the whole time)
 *              return response()->json(['order' => $order], 201);
 *          }
 *
 * ╔══════════════════════════════════════════════════════════════╗
 * ║  AFTER — All Three Fixed                                    ║
 * ╚══════════════════════════════════════════════════════════════╝
 *
 *          ✅ Task 1: Delegates to OrderService which uses
 *             DB::transaction() + lockForUpdate() + InsufficientStockException
 *          ✅ Task 2: Route has 'throttle:orders' middleware (10 req/min/user)
 *          ✅ Task 3: OrderService dispatches async jobs after commit
 *             (GenerateInvoiceJob, SendOrderNotificationsJob, RecordSaleAnalyticsJob)
 *
 * To test each bad version:
 *   1. Task 1: Replace call with direct Product::find() logic (no lock)
 *   2. Task 2: Remove throttle middleware from route
 *   3. Task 3: Call sync services directly instead of dispatching jobs
 * ============================================================ */

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\PlaceOrderRequest;
use App\Services\OrderService;
use Illuminate\Http\Request;

/**
 * OrderController — Authenticated order management.
 *
 * @GET  /api/v1/orders          → index()
 * @POST /api/v1/orders          → store()  [rate limited]
 * @GET  /api/v1/orders/{id}     → show()
 * @POST /api/v1/orders/{id}/cancel → cancel()
 */
class OrderController extends Controller
{
    public function __construct(protected OrderService $orderService) {}

    /** List user's orders (paginated). */
    public function index(Request $request)
    {
        $orders = $this->orderService->getUserOrders(
            $request->user(),
            $request->per_page ?? 15
        );
        return $this->paginated($orders);
    }

    /** Place new order from cart. */
    public function store(PlaceOrderRequest $request)
    {
        try {
            $order = $this->orderService->placeOrder($request->user(), $request->validated());
            return $this->created($order, 'Order placed successfully.');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    /** Get single order. */
    public function show(Request $request, int $id)
    {
        $order = $request->user()
            ->orders()
            ->with(['items.product:id,name,image_url', 'payment'])
            ->findOrFail($id);

        return $this->success($order);
    }

    /** Cancel order. */
    public function cancel(Request $request, int $id)
    {
        $request->validate(['reason' => 'nullable|string|max:500']);

        try {
            $order = $this->orderService->cancelOrder(
                $request->user(),
                $id,
                $request->reason ?? ''
            );
            return $this->success($order, 'Order cancelled.');
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }
}
