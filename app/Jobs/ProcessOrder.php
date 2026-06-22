<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\SemaphoreService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessOrder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;       
    public int $timeout = 120;     
    public int $backoff = 60;      

    public function __construct(public readonly int $orderId) {}

    public function middleware(): array
    {
        return [
            new WithoutOverlapping($this->orderId),
            (new ThrottlesExceptions(5, 10))->backoff(5),
        ];
    }

    public function handle(SemaphoreService $semaphore): void
    {
        $semaphoreKey = 'heavy_report_concurrent';
        $maxConcurrent = 10;

        if (! $semaphore->acquire($semaphoreKey, $maxConcurrent)) {
            $this->release(delay: 5);
            return;
        }

        try {
            $this->process();
        } finally {
            $semaphore->release($semaphoreKey);
        }
    }

    protected function process(): void
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

        $this->sendConfirmationEmail($order);

        if ($order->isPaid() && $order->status === Order::STATUS_CONFIRMED) {
            $order->update(['status' => Order::STATUS_PROCESSING]);
        }

        $this->notifyFulfillment($order);

        Log::info("Order #{$order->order_number} processed successfully.");
    }

    protected function sendConfirmationEmail(Order $order): void
    {
        Log::info("Confirmation email queued for order #{$order->order_number} to {$order->user->email}");
    }

    protected function notifyFulfillment(Order $order): void
    {
        Log::info("Fulfillment notified for order #{$order->order_number}");
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("ProcessOrder job failed for order #{$this->orderId}", [
            'error' => $exception->getMessage(),
        ]);
    }
}
