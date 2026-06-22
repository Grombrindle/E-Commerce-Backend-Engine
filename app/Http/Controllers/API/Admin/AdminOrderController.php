<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateOrderStatusRequest;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminOrderController extends Controller
{
    public function __construct(protected OrderService $orderService) {}

    public function index(Request $request)
    {
        $request->validate([
            'status'    => 'nullable|string',
            'from_date' => 'nullable|date',
            'to_date'   => 'nullable|date',
            'per_page'  => 'nullable|integer|between:1,100',
        ]);

        $orders = Order::with(['user:id,name,email', 'payment:id,order_id,status,amount'])
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->when($request->from_date, fn($q) => $q->whereDate('created_at', '>=', $request->from_date))
            ->when($request->to_date,   fn($q) => $q->whereDate('created_at', '<=', $request->to_date))
            ->orderByDesc('created_at')
            ->paginate($request->per_page ?? 20);

        return $this->paginated($orders);
    }

    public function show(int $id)
    {
        $order = Order::with([
            'user:id,name,email,phone',
            'items.product:id,name,sku,image_url',
            'payment',
        ])->findOrFail($id);

        return $this->success($order);
    }

    public function updateStatus(UpdateOrderStatusRequest $request, int $id)
    {
        $order = $this->orderService->updateStatus($id, $request->status);
        return $this->success($order, 'Order status updated.');
    }

    public function stats()
    {

        $todaySql = \Illuminate\Support\Facades\DB::connection()->getDriverName() === 'sqlite'
            ? "DATE(created_at) = DATE('now')"
            : "DATE(created_at) = CURDATE()";

        $stats = DB::select("
            SELECT
                COUNT(*) as total_orders,
                COALESCE(SUM(CASE WHEN status != 'cancelled' THEN total ELSE 0 END), 0) as total_revenue,
                SUM(CASE WHEN {$todaySql} THEN 1 ELSE 0 END) as orders_today,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_orders,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_orders,
                AVG(CASE WHEN status != 'cancelled' THEN total ELSE NULL END) as avg_order_value
            FROM orders
        ");

        return $this->success($stats[0] ?? []);
    }
}
