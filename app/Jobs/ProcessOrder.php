<?php

/* ============================================================
 * ╔══════════════════════════════════════════════════════════════╗
 * ║  BEFORE — Task 2: No Capacity Control (The Problem)         ║
 * ╚══════════════════════════════════════════════════════════════╝
 *
 * The original job had NO concurrency control. If 1000 orders
 * were placed simultaneously, 1000 jobs ran simultaneously —
 * overwhelming DB connections, CPU, and memory.
 *
 *          Bad code (no middleware, no semaphore):
 *
 *          class HeavyReportJob implements ShouldQueue
 *          {
 *              public function handle(): void
 *              {
 *                  // ⚠ No limit on concurrent execution
 *                  // 1000 of these run simultaneously → 1000 DB connections → crash
 *                  DB::select('SELECT * FROM orders WHERE ...'); // heavy query
 *                  sleep(2); // simulates heavy computation
 *              }
 *          }
 *
 * What was missing:
 *  - No WithoutOverlapping → same job for same order runs multiple times
 *  - No ThrottlesExceptions → repeated failures flood the queue
 *  - No semaphore → unlimited concurrent execution
 *  - No rate limiters on HTTP routes → unlimited incoming requests
 *
 * ╔══════════════════════════════════════════════════════════════╗
 * ║  AFTER — Task 2: Multi-Layer Capacity Control (Fix)         ║
 * ╚══════════════════════════════════════════════════════════════╝
 *
 *          Layer          Mechanism              What it Limits
 *          ─────────────────────────────────────────────────────
 *          HTTP Route     throttle:checkout       60 req/min/user
 *          Queue Middle   WithoutOverlapping      1 job/order
 *          Queue Middle   ThrottlesExceptions     Pause on errors
 *          Redis Semaph   acquire/release         Max N concurrent
 *
 * To test the bad code:
 *   1. Comment out middleware() method
 *   2. Remove semaphore acquire/release from handle()
 *   3. Dispatch 100 orders → see DB connection exhaustion
 *   4. Restore the code → see max 10 concurrent jobs
 * ============================================================ */

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

/**
 * ProcessOrder Job
 *
 * Dispatched after a successful order placement.
 * Runs asynchronously — does NOT block the HTTP response.
 *
 * Capacity controls (Task 2):
 *  - WithoutOverlapping: 1 instance per orderId
 *  - ThrottlesExceptions: pause on repeated errors
 *  - Semaphore: max 10 concurrent executions
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

    /**
     * Job-level middleware:
     *  - WithoutOverlapping: ensures only ONE instance of this job runs per orderId at a time.
     *  - ThrottlesExceptions: if the job throws 5 exceptions in 10 minutes, pause it for 5 minutes.
     */
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

        // Acquire a semaphore slot — if all slots are taken, release back to queue
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
