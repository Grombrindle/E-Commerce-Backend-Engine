<?php

/* ============================================================
 * ╔══════════════════════════════════════════════════════════════╗
 * ║  BEFORE — Task 1: Race Condition (The Problem)              ║
 * ╚══════════════════════════════════════════════════════════════╝
 *
 * The original placeOrder had NO transaction wrapping the
 * stock deduction + order creation. Both could see stale
 * stock and oversell.
 *
 *          Bad code (no transaction, no lock):
 *
 *          public function placeOrder(User $user, array $data): Order
 *          {
 *              $cart = Cart::where('user_id', $user->id)->with('activeItems')->first();
 *
 *              // ⚠ Stock check and deduction OUTSIDE any transaction
 *              foreach ($cart->activeItems as $item) {
 *                  $inventory = Inventory::where('product_id', $item->product_id)->first();
 *
 *                  // ⚠ TOCTOU bug: check and act are separate operations
 *                  if ($inventory->quantity - $inventory->reserved_quantity < $item->quantity) {
 *                      throw new RuntimeException('Insufficient stock');
 *                  }
 *
 *                  // ⚠ Another request can slip in BETWEEN check and decrement
 *                  $inventory->quantity -= $item->quantity;
 *                  $inventory->save();
 *              }
 *
 *              // ⚠ If order creation fails here, stock is already decremented — no rollback!
 *              $order = Order::create([...]);
 *              return $order;
 *          }
 *
 * ╔══════════════════════════════════════════════════════════════╗
 * ║  BEFORE — Task 3: Synchronous Blocking (The Problem)        ║
 * ╚══════════════════════════════════════════════════════════════╝
 *
 * After creating the order, the original code called invoice
 * generation, email sending, and analytics recording SYNCHRONOUSLY
 * — the user waited ~3.9 seconds for a response.
 *
 *          Bad code (blocking sync calls):
 *
 *          // ⚠ ALL BLOCKING: User waits for each one before getting a response
 *          $this->invoiceService->generate($order);       // ~1.5s (PDF generation)
 *          $this->notificationService->sendEmail($order);  // ~1.0s (SMTP call)
 *          $this->analyticsService->recordSale($order);    // ~0.5s (analytics write)
 *
 *          // Total: ~3 seconds for secondary tasks the user doesn't need to wait for!
 *          return response()->json(['order' => $order], 201);
 *
 * What went wrong:
 *  - No async dispatch → every secondary task blocks the HTTP response
 *  - No fault isolation → a broken email service breaks checkout
 *  - No retries → transient failures (SMTP timeout) abort the request
 *
 * ╔══════════════════════════════════════════════════════════════╗
 * ║  AFTER — Task 1: Pessimistic Locking (Fix)                  ║
 * ║  AFTER — Task 3: Async Queue Jobs (Fix)                     ║
 * ╚══════════════════════════════════════════════════════════════╝
 *
 *          ✅ DB::transaction() wraps everything — atomic rollback
 *          ✅ Async dispatch returns immediately (~100ms)
 *          ✅ Each secondary task gets its own queue for scaling
 *          ✅ after_commit => true prevents jobs on rolled-back data
 *
 * To test the bad code:
 *   1. Replace the sync dispatch at bottom with blocking calls
 *   2. Remove DB::transaction() wrapper
 *   3. Fire concurrent orders — see oversell + slow responses
 * ============================================================ */

namespace App\Services;

use App\Exceptions\InsufficientStockException;
use App\Jobs\GenerateInvoiceJob;
use App\Jobs\RecordSaleAnalyticsJob;
use App\Jobs\SendOrderNotificationsJob;
use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Events\OrderPlaced;
use Illuminate\Support\Facades\DB;

/**
 * OrderService — Order placement with atomic inventory deduction.
 *
 * High-concurrency design:
 *  1. Validate cart
 *  2. Open DB transaction
 *  3. Lock inventory rows (SELECT FOR UPDATE)
 *  4. Deduct stock AND release reservations
 *  5. Create order + items
 *  6. Clear cart
 *  7. Commit
 *  8. Dispatch async jobs
 */
class OrderService
{
    public function __construct(
        protected CartService $cartService,
        protected InventoryService $inventoryService,
    ) {
    }

    /**
     * Place an order from the user's current cart.
     *
     * @throws InsufficientStockException|\RuntimeException|\Throwable
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
            $subtotal = 0;
            $itemsData = [];

            foreach ($cart->activeItems as $item) {
                // Pessimistic lock inside transaction
                $this->inventoryService->decrementStock($item->product_id, $item->quantity);

                // Release the reservation that was made when the item was added to cart
                $this->inventoryService->releaseReservation($item->product_id, $item->quantity);

                $lineTotal = $item->quantity * $item->price;
                $subtotal += $lineTotal;

                $itemsData[] = [
                    'product_id' => $item->product_id,
                    'product_name' => $item->product->name,
                    'product_sku' => $item->product->sku,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->price,
                    'subtotal' => $lineTotal,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            $tax = round($subtotal * 0.15, 2); // 15% VAT
            $shippingFee = $subtotal >= 100 ? 0 : 9.99; // Free shipping over $100
            $total = $subtotal + $tax + $shippingFee;

            $order = Order::create([
                'user_id' => $user->id,
                'status' => Order::STATUS_PENDING,
                'subtotal' => $subtotal,
                'tax' => $tax,
                'shipping_fee' => $shippingFee,
                'discount' => 0,
                'total' => $total,
                'shipping_address' => $data['shipping_address'],
                'billing_address' => $data['billing_address'] ?? $data['shipping_address'],
                'notes' => $data['notes'] ?? null,
            ]);

            OrderItem::insert(array_map(fn($i) => array_merge($i, ['order_id' => $order->id]), $itemsData));

            // Clear cart after successful order
            $cart->items()->delete();

            return $order;
        });

        // ============================================================
        // AFTER — Task 3: Async dispatch (non-blocking)
        // ============================================================
        // Each secondary concern gets its own job for independent scaling
        // and fault isolation. If invoice generation fails, notifications
        // and analytics still process.
        GenerateInvoiceJob::dispatch($order)
            ->onQueue('invoices');

        SendOrderNotificationsJob::dispatch($order)
            ->onQueue('notifications')
            ->delay(now()->addSeconds(2));

        RecordSaleAnalyticsJob::dispatch($order)
            ->onQueue('analytics');

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
                'status' => Order::STATUS_CANCELLED,
                'cancelled_at' => now(),
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