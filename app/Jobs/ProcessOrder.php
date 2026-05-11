<?php

namespace App\Jobs;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * ProcessOrder Job
 *
 * Dispatched after a successful order placement.
 * Runs asynchronously — does NOT block the HTTP response.
 *
 * Handles:
 *  1. Send order confirmation email
 *  2. Update analytics/reporting tables
 *  3. Notify warehouse/fulfillment system
 *  4. Apply loyalty points
 */
class ProcessOrder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;       // Retry up to 3 times
    public int $timeout = 120;     // 2 minutes max
    public int $backoff = 60;      // Wait 60s between retries

    public function __construct(public readonly int $orderId) {}

    public function handle(): void
    {
        $order = Order::with(['user', 'items.product'])->find($this->orderId);

        if (!$order) {
            Log::warning("ProcessOrder: Order #{$this->orderId} not found.");
            return;
        }

        Log::info("Processing order #{$order->order_number}", [
            'user_id' => $order->user_id,
            'total'   => $order->total,
            'items'   => $order->items->count(),
        ]);

        // 1. Send confirmation email (via Mail/Notification in production)
        $this->sendConfirmationEmail($order);

        // 2. Update order status to 'processing' if already paid
        if ($order->isPaid() && $order->status === Order::STATUS_CONFIRMED) {
            $order->update(['status' => Order::STATUS_PROCESSING]);
        }

        // 3. Notify fulfillment (webhook/API call in production)
        $this->notifyFulfillment($order);

        Log::info("Order #{$order->order_number} processed successfully.");
    }

    protected function sendConfirmationEmail(Order $order): void
    {
        // In production: Mail::to($order->user)->send(new OrderConfirmationMail($order));
        Log::info("Confirmation email queued for order #{$order->order_number} to {$order->user->email}");
    }

    protected function notifyFulfillment(Order $order): void
    {
        // In production: HTTP call to warehouse API
        Log::info("Fulfillment notified for order #{$order->order_number}");
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("ProcessOrder job failed for order #{$this->orderId}", [
            'error' => $exception->getMessage(),
        ]);
    }
}
