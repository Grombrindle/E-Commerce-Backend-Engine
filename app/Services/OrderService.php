<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Events\OrderPlaced;
use App\Jobs\ProcessOrder;
use Illuminate\Support\Facades\DB;

/**
 * OrderService — Order placement with atomic inventory deduction.
 *
 * High-concurrency design:
 *  1. Validate cart
 *  2. Open DB transaction
 *  3. Lock inventory rows (SELECT FOR UPDATE)
 *  4. Deduct stock
 *  5. Create order + items
 *  6. Clear cart
 *  7. Commit
 *  8. Dispatch async job
 */
class OrderService
{
    public function __construct(
        protected CartService      $cartService,
        protected InventoryService $inventoryService,
    ) {}

    /**
     * Place an order from the user's current cart.
     *
     * @throws \RuntimeException|\Throwable
     */
    public function placeOrder(User $user, array $data): Order
    {
        $cart = Cart::where('user_id', $user->id)
            ->with('activeItems.product.inventory')
            ->first();

        if (!$cart || $cart->isEmpty()) {
            throw new \RuntimeException('Your cart is empty.');
        }

        // Validate stock BEFORE transaction
        $this->cartService->validateForCheckout($cart);

        $order = DB::transaction(function () use ($user, $cart, $data) {
            $subtotal    = 0;
            $itemsData   = [];

            foreach ($cart->activeItems as $item) {
                // Pessimistic lock inside transaction
                $this->inventoryService->decrementStock($item->product_id, $item->quantity);

                $lineTotal   = $item->quantity * $item->price;
                $subtotal   += $lineTotal;

                $itemsData[] = [
                    'product_id'   => $item->product_id,
                    'product_name' => $item->product->name,
                    'product_sku'  => $item->product->sku,
                    'quantity'     => $item->quantity,
                    'unit_price'   => $item->price,
                    'subtotal'     => $lineTotal,
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ];
            }

            $tax         = round($subtotal * 0.15, 2); // 15% VAT
            $shippingFee = $subtotal >= 100 ? 0 : 9.99; // Free shipping over $100
            $total       = $subtotal + $tax + $shippingFee;

            $order = Order::create([
                'user_id'          => $user->id,
                'status'           => Order::STATUS_PENDING,
                'subtotal'         => $subtotal,
                'tax'              => $tax,
                'shipping_fee'     => $shippingFee,
                'discount'         => 0,
                'total'            => $total,
                'shipping_address' => $data['shipping_address'],
                'billing_address'  => $data['billing_address'] ?? $data['shipping_address'],
                'notes'            => $data['notes'] ?? null,
            ]);

            OrderItem::insert(array_map(fn($i) => array_merge($i, ['order_id' => $order->id]), $itemsData));

            // Clear cart after successful order
            $cart->items()->delete();

            return $order;
        });

        // Dispatch async jobs AFTER transaction commits
        ProcessOrder::dispatch($order->id);
        event(new OrderPlaced($order));

        return $order->load('items', 'user');
    }

    /**
     * Cancel an order and restore stock.
     *
     * @throws \RuntimeException
     */
    public function cancelOrder(User $user, int $orderId, string $reason = ''): Order
    {
        $order = Order::where('user_id', $user->id)->findOrFail($orderId);

        if (!$order->isCancellable()) {
            throw new \RuntimeException(
                "Order #{$order->order_number} cannot be cancelled (status: {$order->status})."
            );
        }

        DB::transaction(function () use ($order, $reason) {
            // Restore stock for each item
            foreach ($order->items as $item) {
                if ($item->product_id) {
                    $this->inventoryService->restoreStock($item->product_id, $item->quantity);
                }
            }

            $order->update([
                'status'        => Order::STATUS_CANCELLED,
                'cancelled_at'  => now(),
                'cancel_reason' => $reason,
            ]);
        });

        return $order->fresh(['items']);
    }

    /**
     * Admin: Update order status.
     */
    public function updateStatus(int $orderId, string $status): Order
    {
        $order = Order::findOrFail($orderId);
        $order->update(['status' => $status]);
        return $order->fresh(['items', 'payment', 'user']);
    }

    /**
     * Get paginated orders for a user.
     */
    public function getUserOrders(User $user, int $perPage = 15)
    {
        return Order::where('user_id', $user->id)
            ->with(['items.product:id,name,image_url', 'payment:id,order_id,status,amount'])
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }
}
