<?php

// ═══════════════════════════════════════════════════════════════════════
// BEFORE — Task 8: Two-Step Checkout (No Atomicity)
// ═══════════════════════════════════════════════════════════════════════
//
// Bad checkout() — two separate HTTP requests, no atomicity:
//
//  // Step 1: POST /api/v1/orders → creates order + decrements stock ✅
//  // Step 2: POST /api/v1/payments → processes payment ✅
//  // ⚠ Problem: If step 2 fails, stock is ALREADY decremented — no rollback!
//  // ⚠ Stock goes negative, order stays pending forever
//
// ═══════════════════════════════════════════════════════════════════════
// AFTER (current code): Single DB::transaction() with all-or-nothing atomicity
// ═══════════════════════════════════════════════════════════════════════
namespace App\Services;

use App\Exceptions\InsufficientStockException;
use App\Helpers\AsyncStepLogger;
use App\Jobs\GenerateInvoiceJob;
use App\Jobs\RecordSaleAnalyticsJob;
use App\Jobs\SendOrderNotificationsJob;
use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\User;
use App\Events\OrderPlaced;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckoutService
{
    public function __construct(
        protected CartService $cartService,
        protected InventoryService $inventoryService,
        protected PaymentService $paymentService,
    ) {
    }

    public function checkout(User $user, array $data): array
    {

        $log = (new AsyncStepLogger('checkout'))->withContext([
            'user_id' => $user->id,
        ]);

        $log->start('load_cart');
        $cart = Cart::where('user_id', $user->id)
            ->with('activeItems.product.inventory')
            ->first();

        if (!$cart || $cart->isEmpty()) {
            $log->end('load_cart', 'failed', ['reason' => 'Cart is empty']);
            throw new \RuntimeException('Your cart is empty.');
        }
        $log->end('load_cart', 'success', ['items_count' => $cart->activeItems->count()]);

        $log->start('validate_stock');
        try {
            $this->cartService->validateForCheckout($cart);
            $log->end('validate_stock', 'success');
        } catch (\Throwable $e) {
            $log->end('validate_stock', 'failed', ['error' => $e->getMessage()]);
            throw $e;
        }

        $log->start('process_payment');
        $gatewayResult = $this->simulateGatewayCall($data['payment'] ?? []);
        $log->end('process_payment', 'success', [
            'gateway_transaction' => $gatewayResult['transaction_id'],
        ]);

        $result = DB::transaction(function () use ($user, $cart, $data, $log, $gatewayResult) {

            $log->start('calculate_totals');
            $subtotal = 0;
            $itemsData = [];

            foreach ($cart->activeItems as $item) {
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

            $tax = round($subtotal * 0.15, 2);      
            $shippingFee = $subtotal >= 100 ? 0 : 9.99;     
            $total = $subtotal + $tax + $shippingFee;
            $log->end('calculate_totals', 'success', [
                'subtotal' => $subtotal,
                'tax' => $tax,
                'shipping' => $shippingFee,
                'total' => $total,
            ]);

            $log->start('create_order');
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

            OrderItem::insert(array_map(
                fn($i) => array_merge($i, ['order_id' => $order->id]),
                $itemsData
            ));

            $log->addContext(['order_id' => $order->id]);
            $log->end('create_order', 'success', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
            ]);

            $log->start('record_payment');
            try {
                $payment = $this->processPaymentInternal(
                    $user,
                    $order,
                    $data['payment'],
                    $total,
                    $data['payment']['currency'] ?? 'USD',
                    $gatewayResult
                );
            } catch (\Throwable $e) {
                $log->end('record_payment', 'failed', [
                    'error' => $e->getMessage(),
                    'method' => $data['payment']['method'] ?? 'unknown',
                ]);
                throw $e;
            }
            $log->end('record_payment', 'success', [
                'payment_id' => $payment->id,
                'payment_method' => $payment->method,
                'gateway_transaction' => $payment->gateway_transaction_id,
            ]);

            $log->start('update_inventory');
            try {
                foreach ($cart->activeItems as $item) {
                    $this->inventoryService->decrementStock($item->product_id, $item->quantity);
                    $this->inventoryService->releaseReservation($item->product_id, $item->quantity);
                }
                $log->end('update_inventory', 'success', [
                    'items_processed' => $cart->activeItems->count(),
                ]);
            } catch (\Throwable $e) {
                $log->end('update_inventory', 'failed', [
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }

            $log->start('confirm_order');
            $order->update(['status' => Order::STATUS_CONFIRMED]);
            $log->end('confirm_order', 'success', [
                'order_status' => Order::STATUS_CONFIRMED,
            ]);

            $log->start('clear_cart');
            $cart->items()->delete();
            $log->end('clear_cart', 'success', ['cart_id' => $cart->id]);

            return [
                'order' => $order->fresh(['items', 'user']),
                'payment' => $payment->fresh(),
            ];
        });

        $order = $result['order'];
        $log->start('dispatch_async_jobs');
        GenerateInvoiceJob::dispatch($order)->onQueue('invoices');
        SendOrderNotificationsJob::dispatch($order)
            ->onQueue('notifications')
            ->delay(now()->addSeconds(2));
        RecordSaleAnalyticsJob::dispatch($order)->onQueue('analytics');
        event(new OrderPlaced($order));
        $log->end('dispatch_async_jobs', 'success', [
            'dispatched_jobs' => ['GenerateInvoiceJob', 'SendOrderNotificationsJob', 'RecordSaleAnalyticsJob', 'OrderPlaced'],
        ]);

        \App\Helpers\async_step('checkout', 'info', 'Checkout completed successfully', [
            'step_name' => 'checkout_summary',
            'status' => 'success',
            'user_id' => $user->id,
            'order_id' => $order->id,
            'order_total' => $order->total,
            'payment_id' => $result['payment']->id,
        ]);

        return $result;
    }

    protected function processPaymentInternal(User $user, Order $order, array $paymentData, float $amount, string $currency, array $gatewayResult): Payment
    {

        $payment = Payment::create([
            'order_id' => $order->id,   
            'user_id' => $user->id,
            'method' => $paymentData['method'],
            'status' => Payment::STATUS_PENDING,
            'amount' => $amount,
            'currency' => $currency,
            'gateway' => $paymentData['gateway'] ?? 'internal',
        ]);

        $payment->update([
            'status' => Payment::STATUS_PAID,
            'gateway_transaction_id' => $gatewayResult['transaction_id'],
            'gateway_response' => $gatewayResult,
            'paid_at' => now(),
        ]);

        return $payment;
    }

    protected function simulateGatewayCall(array $data): array
    {

        usleep(random_int(100000, 300000));

        if (isset($data['card_number']) && str_ends_with($data['card_number'], '0002')) {
            throw new \RuntimeException('Your card was declined.');
        }

        return [
            'transaction_id' => 'txn_' . strtoupper(substr(md5(uniqid()), 0, 16)),
            'status' => 'succeeded',
            'gateway' => 'simulated',
            'processed_at' => now()->toIso8601String(),
        ];
    }
}
