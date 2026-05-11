<?php

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
