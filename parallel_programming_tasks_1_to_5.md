# Parallel Programming Project — Tasks 1–5 (Laravel 11)
**Course: Parallel Programming | Semester 2026**
**Framework: Laravel 11 | Focus: Performance & Resource Efficiency**

---

## Table of Contents
1. [Task 1 — Concurrent Access & Data Integrity (Race Condition)](#task-1)
2. [Task 2 — Resource Management & Capacity Control](#task-2)
3. [Task 3 — Asynchronous Queues](#task-3)
4. [Task 4 — Batch Processing (Large Data)](#task-4)
5. [Task 5 — Load Distribution](#task-5)

---

<a name="task-1"></a>
## Task 1 — Concurrent Access & Data Integrity (Race Condition)

### 1. Problem Analysis

When two users attempt to purchase the **same product** at the **same time**, the following sequence can occur:

```
User A reads stock = 1 ✓
User B reads stock = 1 ✓      ← Both pass the stock check!
User A updates: stock = 1 - 1 = 0
User B updates: stock = 0 - 1 = -1  ← OVERSELL! Race condition!
```

The root cause is the **check-then-act** pattern without isolation. The read and write are two separate operations; another thread can slip in between them.

---

### 2. Code Before the Fix (The Problem)

```php
<?php
// app/Http/Controllers/OrderController.php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function placeOrder(Request $request)
    {
        $product = Product::find($request->product_id);

        // ⚠ RACE CONDITION POINT:
        // User A and User B both read stock = 1 here simultaneously.
        // Both pass this check, both proceed to purchase.
        if ($product->stock < $request->quantity) {
            return response()->json(['error' => 'Insufficient stock'], 422);
        }

        // ⚠ No lock between the check above and this update.
        // Both users can reach this line at the same time.
        $product->stock -= $request->quantity;
        $product->save();

        $order = Order::create([
            'user_id'    => $request->user()->id,
            'product_id' => $product->id,
            'quantity'   => $request->quantity,
            'total'      => $product->price * $request->quantity,
        ]);

        return response()->json(['message' => 'Order placed', 'order' => $order], 201);
    }
}
```

**What goes wrong:**
- No database transaction → partial failures leave data inconsistent.
- No row-level lock → concurrent reads see the same stale stock value.
- Result: two orders placed for one remaining item; stock = -1.

---

### 3. Code After the Fix (Pessimistic Locking)

```php
<?php
// app/Http/Controllers/OrderController.php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientStockException;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function placeOrder(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'quantity'   => 'required|integer|min:1',
        ]);

        try {
            $order = DB::transaction(function () use ($validated, $request) {

                // ✅ lockForUpdate() issues:  SELECT * FROM products WHERE id = ? FOR UPDATE
                // This BLOCKS any other transaction from reading or modifying
                // this row until the current transaction commits or rolls back.
                // Only ONE thread can hold this lock at a time.
                $product = Product::where('id', $validated['product_id'])
                                   ->lockForUpdate()
                                   ->firstOrFail();

                if ($product->stock < $validated['quantity']) {
                    // Throwing inside DB::transaction() triggers an automatic rollback.
                    throw new InsufficientStockException('Not enough stock available.');
                }

                // ✅ decrement() is a single atomic UPDATE query — no re-read needed.
                $product->decrement('stock', $validated['quantity']);

                return Order::create([
                    'user_id'    => $request->user()->id,
                    'product_id' => $product->id,
                    'quantity'   => $validated['quantity'],
                    'total'      => $product->price * $validated['quantity'],
                ]);
            });

            return response()->json(['message' => 'Order placed', 'order' => $order], 201);

        } catch (InsufficientStockException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }
}
```

```php
<?php
// app/Exceptions/InsufficientStockException.php

namespace App\Exceptions;

use Exception;

class InsufficientStockException extends Exception {}
```

---

### 4. Explanation of the Fix

| Mechanism | What it does |
|---|---|
| `DB::transaction()` | Wraps all operations in one atomic unit. If anything fails, ALL changes are rolled back. |
| `lockForUpdate()` | Issues `SELECT ... FOR UPDATE` — a row-level exclusive lock. Any other transaction trying to read the same row **waits** until this one finishes. |
| `decrement()` | Executes `UPDATE products SET stock = stock - N WHERE id = ?` — a single atomic SQL statement, no PHP arithmetic needed. |

The sequence with the fix:
```
User A acquires lock on product row ✓
User B tries to read → BLOCKED, waits
User A checks stock = 1, deducts, commits
Lock released
User B reads stock = 0, check fails → "Insufficient stock" ✓
```

---

### 5. Why This Approach Is the Best

- **Zero risk of oversell** — the lock is enforced at the database engine level.
- **Automatic rollback** — if order creation fails after the stock deduction, the decrement is automatically reversed.
- **Built into Laravel** — no external packages needed; `lockForUpdate()` is a native Eloquent feature.
- **Correct for high-contention resources** — when a row is frequently modified by many users, pessimistic locking is the most reliable strategy.

---

### 6. Two Alternative Solutions

**Alternative A — Atomic Conditional Decrement (No Explicit Lock)**

```php
// app/Http/Controllers/OrderController.php

public function placeOrder(Request $request)
{
    $validated = $request->validate([
        'product_id' => 'required|integer|exists:products,id',
        'quantity'   => 'required|integer|min:1',
    ]);

    $updated = DB::transaction(function () use ($validated, $request) {

        // ✅ Single UPDATE with WHERE guards both the check and the update atomically.
        // If stock is insufficient, 0 rows are affected.
        $rowsAffected = Product::where('id', $validated['product_id'])
                                ->where('stock', '>=', $validated['quantity'])
                                ->decrement('stock', $validated['quantity']);

        if ($rowsAffected === 0) {
            throw new \Exception('Insufficient stock or product not found.');
        }

        $product = Product::find($validated['product_id']);

        return Order::create([
            'user_id'    => $request->user()->id,
            'product_id' => $product->id,
            'quantity'   => $validated['quantity'],
            'total'      => $product->price * $validated['quantity'],
        ]);
    });

    return response()->json(['message' => 'Order placed', 'order' => $updated], 201);
}
```

**Alternative B — Optimistic Locking (Version Column)**

```php
// Migration: add version column
Schema::table('products', function (Blueprint $table) {
    $table->unsignedBigInteger('version')->default(0);
});
```

```php
// app/Http/Controllers/OrderController.php

public function placeOrder(Request $request)
{
    $maxRetries = 3;
    $attempts   = 0;

    while ($attempts < $maxRetries) {
        $product = Product::find($request->product_id);

        if ($product->stock < $request->quantity) {
            return response()->json(['error' => 'Insufficient stock'], 422);
        }

        // ✅ Try to update ONLY IF the version hasn't changed since we read it.
        // If another transaction modified the row, version is now different → 0 rows affected.
        $updated = Product::where('id', $product->id)
                           ->where('version', $product->version)
                           ->where('stock', '>=', $request->quantity)
                           ->update([
                               'stock'   => $product->stock - $request->quantity,
                               'version' => $product->version + 1,
                           ]);

        if ($updated > 0) {
            $order = Order::create([
                'user_id'    => $request->user()->id,
                'product_id' => $product->id,
                'quantity'   => $request->quantity,
                'total'      => $product->price * $request->quantity,
            ]);
            return response()->json(['message' => 'Order placed', 'order' => $order], 201);
        }

        $attempts++;
        usleep(random_int(1000, 5000)); // small random back-off before retry
    }

    return response()->json(['error' => 'Could not process order, please try again.'], 409);
}
```

---

### 7. Why the Alternatives Are Less Suitable

| Approach | Weakness |
|---|---|
| **Alternative A** (Atomic Decrement) | Requires a second query to fetch the product for order creation. Under extreme load, slightly less readable. Good but still slightly more manual than the lock approach. |
| **Alternative B** (Optimistic Locking) | Best when **conflicts are rare**. With high-contention (many users, few items), it produces many retries and wasted CPU cycles. The retry loop adds complexity. No built-in Laravel support — you manage versions manually. |

**Pessimistic locking wins** for inventory management because:
- Contention is **expected** (flash sales, limited stock).
- Retries waste resources under heavy load.
- The lock guarantees correctness in a single attempt.

---

### 8. Why the Problem Happened

The race condition occurred because of the **TOCTOU (Time-Of-Check to Time-Of-Use)** pattern — the state check (stock >= quantity) and the state change (stock -= quantity) were **two separate, non-atomic operations**. Any thread executing in the window between these two operations reads stale data, leading to a logically inconsistent final state.

---
---

<a name="task-2"></a>
## Task 2 — Resource Management & Capacity Control

### 1. Problem Analysis

Without capacity controls, if 1,000 users hit `/checkout` simultaneously:
- 1,000 database connections are opened → connection pool exhausted.
- 1,000 jobs are dispatched instantly → queue workers overwhelmed.
- Memory usage spikes → PHP-FPM processes crash.
- Result: **server down**, all 1,000 requests fail.

The goal is to allow enough concurrency for good throughput, while capping it before system resources are exhausted. This is a **semaphore** problem.

---

### 2. Code Before the Fix (No Capacity Control)

```php
<?php
// app/Http/Controllers/OrderController.php  — NO THROTTLING

class OrderController extends Controller
{
    public function checkout(Request $request)
    {
        // ⚠ No rate limiting. 1000 simultaneous requests all execute here at once.
        $order = $this->processCheckout($request);

        // ⚠ All 1000 dispatch jobs simultaneously — queue overwhelmed.
        HeavyReportJob::dispatch($order);

        return response()->json(['order' => $order]);
    }
}
```

```php
<?php
// app/Jobs/HeavyReportJob.php  — NO CONCURRENCY CONTROL

class HeavyReportJob implements ShouldQueue
{
    public function handle(): void
    {
        // ⚠ No limit on how many of these run in parallel.
        // 1000 queued simultaneously → 1000 DB connections → crash.
        DB::select('SELECT * FROM orders WHERE ...'); // heavy query
        sleep(2); // simulates heavy computation
    }
}
```

---

### 3. Code After the Fix (Semaphore + Queue Throttling)

**Step 1: Configure Rate Limiters in `AppServiceProvider`**

```php
<?php
// app/Providers/AppServiceProvider.php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // ✅ API-level throttle: max 60 checkout requests per minute per user
        RateLimiter::for('checkout', function (Request $request) {
            return Limit::perMinute(60)
                        ->by($request->user()?->id ?: $request->ip())
                        ->response(function () {
                            return response()->json([
                                'error' => 'Too many requests. Please wait and try again.',
                            ], 429);
                        });
        });

        // ✅ Heavy operations: max 10 per minute system-wide (not per user)
        RateLimiter::for('heavy-processing', function (Request $request) {
            return Limit::perMinute(10)->by('system');
        });
    }
}
```

**Step 2: Apply Throttle to Routes**

```php
<?php
// routes/api.php

use App\Http\Controllers\OrderController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'throttle:checkout'])->group(function () {
    Route::post('/checkout', [OrderController::class, 'checkout']);
});
```

**Step 3: Redis Semaphore Service (Limits Concurrent DB-Heavy Operations)**

```php
<?php
// app/Services/SemaphoreService.php

namespace App\Services;

use Illuminate\Support\Facades\Redis;

class SemaphoreService
{
    /**
     * Atomically acquires a semaphore slot.
     * Uses a Lua script to guarantee the check-and-increment is atomic
     * (no race condition on the semaphore itself).
     */
    public function acquire(string $key, int $maxConcurrent, int $ttlSeconds = 30): bool
    {
        $luaScript = <<<LUA
        local current = tonumber(redis.call('GET', KEYS[1]) or 0)
        if current >= tonumber(ARGV[1]) then
            return 0
        end
        redis.call('INCR', KEYS[1])
        redis.call('EXPIRE', KEYS[1], ARGV[2])
        return 1
        LUA;

        return (bool) Redis::eval($luaScript, 1, $key, $maxConcurrent, $ttlSeconds);
    }

    public function release(string $key): void
    {
        // Decrement but never go below 0
        $luaScript = <<<LUA
        local current = tonumber(redis.call('GET', KEYS[1]) or 0)
        if current > 0 then
            redis.call('DECR', KEYS[1])
        end
        return 1
        LUA;

        Redis::eval($luaScript, 1, $key);
    }
}
```

**Step 4: Job With Concurrency Middleware**

```php
<?php
// app/Jobs/HeavyReportJob.php

namespace App\Jobs;

use App\Services\SemaphoreService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class HeavyReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // ✅ Laravel retries the job 3 times before marking it failed
    public int $tries = 3;

    // ✅ Wait 60 seconds before retrying a failed attempt
    public int $backoff = 60;

    public function __construct(private int $orderId) {}

    /**
     * ✅ Job-level middleware:
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
        $maxConcurrent = 10; // ✅ Allow max 10 of these jobs to run in parallel

        if (! $semaphore->acquire($semaphoreKey, $maxConcurrent)) {
            // ✅ If all 10 slots are taken, re-queue this job to try later
            $this->release(delay: 5);
            return;
        }

        try {
            // Heavy DB or computation work
            $this->generateReport($this->orderId);
        } finally {
            // ✅ Always release the semaphore slot, even on exception
            $semaphore->release($semaphoreKey);
        }
    }

    private function generateReport(int $orderId): void
    {
        Log::info("Generating report for order #{$orderId}");
        // ... heavy processing ...
    }
}
```

**Step 5: Supervisor Configuration (Controls Worker Count)**

```ini
; /etc/supervisor/conf.d/laravel-worker.conf

[program:laravel-worker-default]
command=php /var/www/artisan queue:work redis --queue=default --sleep=3 --tries=3 --max-jobs=500
numprocs=5          ; ✅ Max 5 parallel workers for the default queue
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/laravel-worker.log

[program:laravel-worker-heavy]
command=php /var/www/artisan queue:work redis --queue=heavy --sleep=3 --tries=3
numprocs=2          ; ✅ Only 2 workers for heavy jobs — intentionally limited
autostart=true
autorestart=true
```

---

### 4. Explanation of the Fix

| Layer | Mechanism | What it Limits |
|---|---|---|
| HTTP Route | `throttle:checkout` middleware | Max 60 checkout requests per user per minute |
| Job Middleware | `WithoutOverlapping` | Max 1 instance of the same job (per order) in queue |
| Job Middleware | `ThrottlesExceptions` | Pauses job queue if repeated errors detected |
| Redis Semaphore | Lua atomic script | Max N jobs executing concurrently at any moment |
| Supervisor | `numprocs` | Max N worker processes consuming server CPU/RAM |

This creates a **multi-layer defense**: request throttling at the HTTP level, job uniqueness at the queue level, and concurrency caps at execution level.

---

### 5. Why This Approach Is the Best

- **Defense in depth** — if one layer is bypassed, the next catches it.
- **Resource-aware** — the semaphore can be tuned to match server capacity.
- **No data loss** — jobs are re-queued when the semaphore is full, not dropped.
- **Laravel-native** — all middleware classes and `RateLimiter` are built-in.
- **Atomic semaphore** — the Lua script prevents a race condition on the semaphore counter itself.

---

### 6. Two Alternative Solutions

**Alternative A — Laravel's Built-in Queue Rate Limiter**

```php
// app/Providers/AppServiceProvider.php

use Illuminate\Queue\Middleware\RateLimited;

// Define rate limit for jobs
RateLimiter::for('reports', function (object $job) {
    return Limit::perMinute(10)->by('system');
});
```

```php
// app/Jobs/HeavyReportJob.php

public function middleware(): array
{
    return [new RateLimited('reports')];
}
```

**Alternative B — Connection Pool Limiting via `config/database.php`**

```php
// config/database.php

'mysql' => [
    'driver'   => 'mysql',
    'host'     => env('DB_HOST', '127.0.0.1'),
    // ... other settings ...
    'options'  => [
        PDO::ATTR_PERSISTENT => false, // ✅ No persistent connections
    ],
    // ✅ Use PgBouncer or ProxySQL externally to cap pool at ~50 connections
],
```

---

### 7. Why the Alternatives Are Less Suitable

| Alternative | Weakness |
|---|---|
| **A — Built-in RateLimited middleware** | Operates at the queue-dispatch level (per minute), not at the concurrency level (how many run *right now*). It slows the intake rate but doesn't cap simultaneous execution. |
| **B — DB Connection Pool** | Only limits the database bottleneck. CPU and memory can still be overwhelmed by jobs that don't hit the DB. Also requires external tools (PgBouncer) — not purely Laravel. |

The **combined semaphore + throttle + supervisor** approach addresses **all** resource types (DB, CPU, memory, queue depth) simultaneously.

---

### 8. Why the Problem Happened

Without capacity controls, Laravel's queue system and web server (PHP-FPM) are **optimistic by default** — they accept and execute as many concurrent jobs/requests as the server can spawn. Under a sudden traffic spike, the number of concurrent processes exceeds available resources, causing cascading failures: DB connection limits, memory exhaustion, and CPU saturation all hit simultaneously.

---
---

<a name="task-3"></a>
## Task 3 — Asynchronous Queues

### 1. Problem Analysis

When a user places an order, several secondary operations must happen:
- Generate and store a PDF invoice
- Send a confirmation email
- Update analytics dashboards
- Notify the warehouse

None of these require the user to **wait**. Doing them synchronously in the controller response cycle adds 2–5 seconds of unnecessary latency to every checkout request.

**Async queues** move these tasks to background workers, returning the HTTP response immediately (~50ms) while the heavy work happens independently.

---

### 2. Code Before the Fix (Synchronous — Blocking)

```php
<?php
// app/Http/Controllers/OrderController.php  — SYNCHRONOUS (SLOW)

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\InvoiceService;
use App\Services\NotificationService;
use App\Services\AnalyticsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function __construct(
        private InvoiceService      $invoiceService,
        private NotificationService $notificationService,
        private AnalyticsService    $analyticsService,
    ) {}

    public function checkout(Request $request)
    {
        // Core order creation — must be synchronous ✓
        $order = DB::transaction(fn() => $this->createOrder($request));

        // ⚠ BLOCKING: User waits for ALL of these before getting a response
        $this->invoiceService->generate($order);          // ~1.5 seconds (PDF generation)
        $this->notificationService->sendEmail($order);     // ~1.0 second  (SMTP call)
        $this->notificationService->sendSms($order);       // ~0.8 seconds (SMS API)
        $this->analyticsService->recordSale($order);       // ~0.5 seconds (analytics write)

        // ⚠ Total response time: ~3.8 seconds just for secondary tasks!
        return response()->json(['order' => $order], 201);
    }
}
```

**Timeline (before):**
```
Request → Create Order (100ms) → Generate Invoice (1500ms) → Send Email (1000ms)
       → Send SMS (800ms) → Analytics (500ms) → Response  ≈ 3.9 seconds
```

---

### 3. Code After the Fix (Asynchronous Queue Jobs)

**Step 1: Create the Job classes**

```php
<?php
// app/Jobs/GenerateInvoiceJob.php

namespace App\Jobs;

use App\Models\Order;
use App\Services\InvoiceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateInvoiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;   // ✅ Retry up to 3 times on failure
    public int $timeout = 60;  // ✅ Kill the job if it runs longer than 60 seconds

    public function __construct(private Order $order) {}

    public function handle(InvoiceService $invoiceService): void
    {
        $invoiceService->generate($this->order);
    }

    // ✅ If all retries are exhausted, log the failure
    public function failed(\Throwable $exception): void
    {
        \Log::error("Invoice generation failed for order #{$this->order->id}: {$exception->getMessage()}");
    }
}
```

```php
<?php
// app/Jobs/SendOrderNotificationsJob.php

namespace App\Jobs;

use App\Models\Order;
use App\Notifications\OrderConfirmedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendOrderNotificationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 5;
    public int $backoff = 30; // ✅ Wait 30 seconds between retries (e.g. SMTP timeout)

    public function __construct(private Order $order) {}

    public function handle(): void
    {
        $this->order->user->notify(new OrderConfirmedNotification($this->order));
    }
}
```

```php
<?php
// app/Jobs/RecordSaleAnalyticsJob.php

namespace App\Jobs;

use App\Models\Order;
use App\Services\AnalyticsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RecordSaleAnalyticsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(private Order $order) {}

    public function handle(AnalyticsService $analyticsService): void
    {
        $analyticsService->recordSale($this->order);
    }
}
```

**Step 2: Dispatch jobs from the Controller**

```php
<?php
// app/Http/Controllers/OrderController.php  — ASYNC (FAST)

namespace App\Http\Controllers;

use App\Jobs\GenerateInvoiceJob;
use App\Jobs\RecordSaleAnalyticsJob;
use App\Jobs\SendOrderNotificationsJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function checkout(Request $request)
    {
        // ✅ Core operation — stays synchronous (atomic transaction)
        $order = DB::transaction(function () use ($request) {
            return $this->createOrder($request);
        });

        // ✅ All secondary tasks dispatched to background queues.
        // These return immediately — they do NOT block the response.

        GenerateInvoiceJob::dispatch($order)
            ->onQueue('invoices');         // Dedicated queue for invoice work

        SendOrderNotificationsJob::dispatch($order)
            ->onQueue('notifications')
            ->delay(now()->addSeconds(2)); // ✅ Small delay: wait for order to fully persist

        RecordSaleAnalyticsJob::dispatch($order)
            ->onQueue('analytics');

        // ✅ Response is returned immediately after dispatching — ~100ms total
        return response()->json([
            'message' => 'Order placed successfully.',
            'order'   => $order,
        ], 201);
    }
}
```

**Step 3: Queue configuration (`.env`)**

```ini
# .env
QUEUE_CONNECTION=redis
```

```php
// config/queue.php — named queues with priorities
'connections' => [
    'redis' => [
        'driver'       => 'redis',
        'connection'   => 'default',
        'queue'        => env('REDIS_QUEUE', 'default'),
        'retry_after'  => 90,
        'block_for'    => null,
        'after_commit' => true,  // ✅ Only dispatch after the DB transaction commits
    ],
],
```

**Step 4: Run workers for each queue**

```bash
# Process invoices (high priority, 3 workers)
php artisan queue:work redis --queue=invoices --tries=3

# Process notifications (2 workers)
php artisan queue:work redis --queue=notifications --tries=5

# Process analytics (low priority, 1 worker)
php artisan queue:work redis --queue=analytics --tries=2
```

**Timeline (after):**
```
Request → Create Order (100ms) → Dispatch 3 jobs (~5ms each) → Response ≈ 115ms
                                       ↓ (background, invisible to user)
                             Worker: Invoice (1500ms)
                             Worker: Email   (1000ms)
                             Worker: SMS     (800ms)
```

---

### 4. Explanation of the Fix

- **`ShouldQueue` interface** — tells Laravel this job should be pushed to the queue instead of run immediately.
- **`dispatch()`** — serializes the job and pushes it to the Redis queue. Returns instantly.
- **`onQueue('name')`** — routes jobs to dedicated queues, allowing independent worker scaling per job type.
- **`delay()`** — prevents race conditions where a job runs before its dependent data is fully committed.
- **`after_commit => true`** — ensures jobs are not dispatched until the wrapping DB transaction has committed (prevents jobs running on rolled-back data).

---

### 5. Why This Approach Is the Best

- **~97% reduction in response time** (3.9s → ~115ms).
- **Fault isolation** — a broken invoice service doesn't fail the checkout.
- **Automatic retries** — transient failures (email timeout, PDF crash) are retried automatically.
- **Independent scaling** — invoice workers can be scaled separately from notification workers.
- **`after_commit` safety** — eliminates a subtle bug where a job processes an order that was rolled back.

---

### 6. Two Alternative Solutions

**Alternative A — Laravel Events & Listeners**

```php
// app/Events/OrderPlaced.php
class OrderPlaced
{
    public function __construct(public Order $order) {}
}

// app/Listeners/SendOrderNotifications.php
class SendOrderNotifications implements ShouldQueue  // ✅ Still async
{
    public function handle(OrderPlaced $event): void
    {
        $event->order->user->notify(new OrderConfirmedNotification($event->order));
    }
}

// In the controller:
event(new OrderPlaced($order));
```

**Alternative B — Invokable Job Chain**

```php
// Chain jobs sequentially — each starts after the previous completes
GenerateInvoiceJob::dispatch($order)
    ->chain([
        new SendOrderNotificationsJob($order),
        new RecordSaleAnalyticsJob($order),
    ]);
```

---

### 7. Why the Alternatives Are Less Suitable

| Alternative | Weakness |
|---|---|
| **A — Events/Listeners** | Good for decoupling, but hides the async flow in event listener registration. Harder to trace for debugging. Chaining and prioritizing becomes less explicit. |
| **B — Job Chain** | Jobs run **sequentially**, not in parallel. Total background time = sum of all job durations. Use this only when jobs have strict ordering dependencies. |

**Parallel dispatch** (chosen approach) runs all background jobs simultaneously, so total background time = duration of the **longest** single job, not their sum.

---

### 8. Why the Problem Happened

Synchronous HTTP controllers were designed for the request-response cycle. Without explicit async dispatch, every line of code in the controller blocks the response. Secondary tasks (email, invoice) were written as normal service method calls, inheriting the synchronous nature of the HTTP request. There was no mechanism to "fire and forget."

---
---

<a name="task-4"></a>
## Task 4 — Batch Processing (Large Data)

### 1. Problem Analysis

Processing 100,000 sales records for a daily report requires reading them from the database. Two problems arise:

1. **Memory**: `Order::all()` loads 100k Eloquent models into PHP memory simultaneously → memory exhaustion.
2. **Time**: Processing them in a single loop in one job → job timeout.

The solution is to **chunk** the data: process N records at a time, so memory stays bounded, and optionally process chunks **in parallel** using `Bus::batch()`.

---

### 2. Code Before the Fix (Memory-Killing Approach)

```php
<?php
// app/Console/Commands/ProcessDailySales.php  — DANGEROUS

namespace App\Console\Commands;

use App\Models\Order;
use Illuminate\Console\Command;

class ProcessDailySales extends Command
{
    protected $signature   = 'sales:process-daily';
    protected $description = 'Process daily sales report';

    public function handle(): void
    {
        // ⚠ Loads ALL records into memory at once.
        // 100,000 orders × ~2KB per Eloquent model = ~200MB RAM spike.
        // PHP memory_limit exceeded → Fatal Error → no report generated.
        $orders = Order::whereDate('created_at', today())->get();

        $totalRevenue = 0;
        $totalOrders  = 0;

        foreach ($orders as $order) {
            // ⚠ All 100k models sit in RAM while this loop runs
            $totalRevenue += $order->total;
            $totalOrders++;
            $this->processOrderForReport($order);
        }

        $this->info("Processed {$totalOrders} orders. Total revenue: {$totalRevenue}");
    }
}
```

---

### 3. Code After the Fix (Chunked Batch Processing)

**Approach: `chunk()` for memory safety + `Bus::batch()` for parallel execution**

**Step 1: The chunk processor job**

```php
<?php
// app/Jobs/ProcessSalesChunkJob.php

namespace App\Jobs;

use App\Models\DailySalesReport;
use App\Models\Order;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessSalesChunkJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 120;

    /**
     * @param array<int> $orderIds  IDs only — NOT full models (keeps payload small)
     * @param string     $date
     */
    public function __construct(
        private array  $orderIds,
        private string $date
    ) {}

    public function handle(): void
    {
        // ✅ Check if the whole batch was cancelled before doing work
        if ($this->batch()?->cancelled()) {
            return;
        }

        // ✅ Fetch only this chunk's records — memory stays bounded to chunk size
        $orders = Order::whereIn('id', $this->orderIds)->get();

        $chunkRevenue = $orders->sum('total');
        $chunkCount   = $orders->count();

        // ✅ Upsert partial results — atomic, safe for concurrent chunk writers
        DailySalesReport::upsert([
            [
                'date'          => $this->date,
                'chunk_revenue' => $chunkRevenue,
                'chunk_count'   => $chunkCount,
                'processed_at'  => now(),
            ]
        ], ['date'], ['chunk_revenue', 'chunk_count', 'processed_at']);
    }
}
```

**Step 2: The orchestrator job that builds the batch**

```php
<?php
// app/Jobs/DispatchDailySalesBatchJob.php

namespace App\Jobs;

use App\Models\Order;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Throwable;

class DispatchDailySalesBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private string $date) {}

    public function handle(): void
    {
        $jobs      = [];
        $chunkSize = 500; // ✅ Process 500 records per chunk — tune based on server RAM

        // ✅ chunk() fetches 500 rows at a time from DB — never loads all records at once.
        // Memory usage stays at ~1MB per chunk regardless of total record count.
        Order::whereDate('created_at', $this->date)
             ->select('id')  // ✅ Select only IDs to keep the chunk fetch ultra-light
             ->chunk($chunkSize, function ($orders) use (&$jobs) {
                 $jobs[] = new ProcessSalesChunkJob(
                     $orders->pluck('id')->toArray(),
                     $this->date
                 );
             });

        if (empty($jobs)) {
            Log::info("No orders found for {$this->date}.");
            return;
        }

        // ✅ Bus::batch() dispatches all chunk jobs in PARALLEL.
        // Workers process multiple chunks simultaneously.
        Bus::batch($jobs)
           ->name("Daily Sales Report — {$this->date}")
           ->onQueue('batch-processing')
           ->allowFailures()  // ✅ One failing chunk doesn't cancel the whole batch
           ->then(function (Batch $batch) {
               Log::info("Daily sales batch complete. Processed {$batch->totalJobs} chunks.");
               // Optionally: aggregate chunk results into a final report here
           })
           ->catch(function (Batch $batch, Throwable $e) {
               Log::error("Batch processing error: {$e->getMessage()}", [
                   'failed_jobs'    => $batch->failedJobs,
                   'total_jobs'     => $batch->totalJobs,
               ]);
           })
           ->finally(function (Batch $batch) {
               Log::info("Batch {$batch->id} finished. Success: {$batch->processedJobs()}/{$batch->totalJobs}");
           })
           ->dispatch();
    }
}
```

**Step 3: Schedule the batch job**

```php
<?php
// routes/console.php  (Laravel 11 — replaces Kernel.php scheduling)

use App\Jobs\DispatchDailySalesBatchJob;
use Illuminate\Support\Facades\Schedule;

Schedule::job(new DispatchDailySalesBatchJob(today()->toDateString()))
         ->dailyAt('02:00')           // ✅ Run at 2 AM when traffic is low
         ->withoutOverlapping()        // ✅ Don't run if previous run is still in progress
         ->runInBackground()           // ✅ Don't block the scheduler process
         ->emailOutputOnFailure(env('ADMIN_EMAIL'));
```

**Step 4: Create the batch jobs table**

```bash
php artisan queue:batches-table
php artisan migrate
```

---

### 4. Explanation of the Fix

| Technique | What it Does |
|---|---|
| `chunk(500)` | Fetches 500 rows at a time from DB. PHP memory never exceeds ~1-2MB for this operation, regardless of total rows. |
| `select('id')` | Only fetches IDs in the chunk query. Full models are loaded inside each job where they're actually needed. |
| `Bus::batch()` | Dispatches all chunk jobs simultaneously. Workers process them in parallel, drastically reducing total time. |
| `allowFailures()` | One bad chunk (e.g. a corrupted record) doesn't abort the entire batch. |
| `->then()` / `->catch()` | Lifecycle hooks for logging and alerting after the full batch completes or fails. |
| `withoutOverlapping()` | Prevents a second batch from starting if the first is still running (e.g. due to a delay). |

---

### 5. Why This Approach Is the Best

- **Memory-bounded**: RAM usage = O(chunk_size), not O(total_records). 100k records use the same RAM as 500 records.
- **Parallel**: Multiple chunks processed simultaneously by multiple workers → 10x speedup vs sequential.
- **Fault-tolerant**: Failed chunks are retried independently; the rest continue.
- **Observable**: Laravel's batch dashboard (`/horizon` or `Bus::findBatch($id)`) shows real-time progress.
- **Schedulable**: Runs automatically at off-peak hours with a single line in `routes/console.php`.

---

### 6. Two Alternative Solutions

**Alternative A — `LazyCollection` with `cursor()`**

```php
// Memory-efficient streaming without chunking logic
$orders = Order::whereDate('created_at', today())->cursor(); // Uses PDO cursor (generator)

foreach ($orders as $order) {
    // ✅ Only ONE model in memory at a time — extremely memory efficient
    $this->processOrderForReport($order);
}
```

**Alternative B — `chunkById()` for safer pagination**

```php
// chunkById uses keyset pagination — safe if records are inserted during processing
Order::whereDate('created_at', today())
     ->chunkById(500, function ($orders) {
         foreach ($orders as $order) {
             $this->processOrderForReport($order);
         }
     });
```

---

### 7. Why the Alternatives Are Less Suitable

| Alternative | Weakness |
|---|---|
| **A — `cursor()`** | Streams records one-by-one — minimal memory, but **sequential only**. Cannot parallelize easily. If one record fails, the whole stream may fail. Best for simple sequential transformations, not parallelizable batch work. |
| **B — `chunkById()`** | Sequential like `cursor()`. Better than `chunk()` for large ordered tables (avoids offset issues), but still single-threaded. Good for simpler use cases without the need for parallel workers. |

`Bus::batch()` with `chunk()` is the only approach that achieves **both** memory efficiency and **parallel execution** simultaneously.

---

### 8. Why the Problem Happened

`Eloquent::get()` is a terminal operation that executes the full SQL query and hydrates all results into a PHP array of model objects. This is convenient for small datasets but becomes catastrophic at scale because PHP arrays are not lazy — all data must fit in memory at once. There was no awareness that memory is a bounded resource that must be managed like CPU time.

---
---

<a name="task-5"></a>
## Task 5 — Load Distribution

### 1. Problem Analysis

A single server handling all requests creates a **single point of failure** and a **performance ceiling**. Load distribution (load balancing) spreads incoming requests across multiple servers (or virtual workers) to:
- Prevent any one server from being overwhelmed.
- Allow horizontal scaling.
- Improve fault tolerance — if one server fails, others continue.

In a Laravel homework context, we simulate this by:
1. Implementing a **Round-Robin Load Balancer Service** (software-level routing logic) backed by a Redis atomic counter.
2. Mapping to multiple **Laravel queue connections** that simulate separate server queues.

**Strategy chosen: Weighted Round-Robin**, which distributes requests proportionally based on each server's declared capacity.

---

### 2. Code Before the Fix (Single Server — No Distribution)

```php
<?php
// app/Http/Controllers/OrderController.php  — SINGLE SERVER

class OrderController extends Controller
{
    public function checkout(Request $request)
    {
        // ⚠ Every request goes to the same processing pipeline.
        // No distribution — one server bears all load.
        ProcessOrderJob::dispatch($request->all())
                        ->onQueue('default'); // Only one queue — one server

        return response()->json(['status' => 'Processing']);
    }
}
```

```php
// config/queue.php  — Only one connection defined
'connections' => [
    'redis' => [
        'driver' => 'redis',
        'queue'  => 'default',
    ],
],
```

---

### 3. Code After the Fix (Weighted Round-Robin Load Balancer)

**Step 1: Define multiple server queues in `config/queue.php`**

```php
<?php
// config/queue.php

return [
    'default' => env('QUEUE_CONNECTION', 'redis'),

    'connections' => [
        // ✅ Each connection simulates a separate server
        'server_1' => [
            'driver'       => 'redis',
            'connection'   => 'default',
            'queue'        => 'server_1_orders',
            'retry_after'  => 90,
            'block_for'    => null,
            'after_commit' => true,
        ],
        'server_2' => [
            'driver'       => 'redis',
            'connection'   => 'default',
            'queue'        => 'server_2_orders',
            'retry_after'  => 90,
            'block_for'    => null,
            'after_commit' => true,
        ],
        'server_3' => [
            'driver'       => 'redis',
            'connection'   => 'default',
            'queue'        => 'server_3_orders',
            'retry_after'  => 90,
            'block_for'    => null,
            'after_commit' => true,
        ],
    ],
];
```

**Step 2: Load Balancer configuration**

```php
<?php
// config/load_balancer.php

return [
    'servers' => [
        [
            'name'       => 'server_1',
            'queue'      => 'server_1_orders',
            'connection' => 'server_1',
            'weight'     => 3,  // ✅ Gets 3/6 = 50% of traffic (most powerful server)
        ],
        [
            'name'       => 'server_2',
            'queue'      => 'server_2_orders',
            'connection' => 'server_2',
            'weight'     => 2,  // ✅ Gets 2/6 = 33% of traffic
        ],
        [
            'name'       => 'server_3',
            'queue'      => 'server_3_orders',
            'connection' => 'server_3',
            'weight'     => 1,  // ✅ Gets 1/6 = 17% of traffic (least powerful)
        ],
    ],
];
```

**Step 3: The Weighted Round-Robin Load Balancer Service**

```php
<?php
// app/Services/LoadBalancerService.php

namespace App\Services;

use Illuminate\Support\Facades\Redis;
use RuntimeException;

class LoadBalancerService
{
    /** @var array<int, array> Expanded list with each server repeated by its weight */
    private array $weightedPool;

    private string $counterKey = 'load_balancer:rr_counter';

    public function __construct()
    {
        $this->weightedPool = $this->buildWeightedPool();
    }

    /**
     * Returns the next server connection name using Weighted Round-Robin.
     *
     * Uses an atomic Redis INCR to get the next index without a race condition
     * on the counter itself.
     */
    public function nextConnection(): string
    {
        if (empty($this->weightedPool)) {
            throw new RuntimeException('No servers configured in load_balancer.php');
        }

        // ✅ INCR is atomic — no two requests get the same counter value.
        // Modulo maps the ever-increasing counter back to a pool index.
        $index  = Redis::incr($this->counterKey) % count($this->weightedPool);
        $server = $this->weightedPool[$index];

        return $server['connection'];
    }

    /**
     * Returns a server from the pool by a consistent hash of the provided key.
     * Useful for session affinity (always route user X to the same server).
     */
    public function connectionByAffinity(string $key): string
    {
        $index = crc32($key) % count($this->weightedPool);
        return $this->weightedPool[abs($index)]['connection'];
    }

    /**
     * Builds a flat array where each server appears N times equal to its weight.
     * Example: weights [3, 2, 1] → pool = [s1, s1, s1, s2, s2, s3]
     */
    private function buildWeightedPool(): array
    {
        $pool = [];
        foreach (config('load_balancer.servers') as $server) {
            for ($i = 0; $i < ($server['weight'] ?? 1); $i++) {
                $pool[] = $server;
            }
        }
        return $pool;
    }

    /**
     * Returns current load stats for monitoring.
     * @return array<string, int>
     */
    public function getQueueLengths(): array
    {
        $stats = [];
        foreach (config('load_balancer.servers') as $server) {
            $stats[$server['name']] = Redis::llen($server['queue']);
        }
        return $stats;
    }
}
```

**Step 4: Use the Load Balancer in the Controller**

```php
<?php
// app/Http/Controllers/OrderController.php  — WITH LOAD DISTRIBUTION

namespace App\Http\Controllers;

use App\Jobs\ProcessOrderJob;
use App\Services\LoadBalancerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function __construct(private LoadBalancerService $loadBalancer) {}

    public function checkout(Request $request)
    {
        // Core order creation stays synchronous (atomic transaction)
        $order = DB::transaction(fn() => $this->createOrder($request));

        // ✅ Load balancer selects the next server using Weighted Round-Robin.
        // Requests are distributed proportionally across all configured servers.
        $connection = $this->loadBalancer->nextConnection();

        ProcessOrderJob::dispatch($order)
                        ->onConnection($connection);

        return response()->json([
            'message'    => 'Order placed.',
            'order'      => $order,
            'routed_to'  => $connection, // ✅ Include in response for monitoring/debugging
        ], 201);
    }
}
```

**Step 5: Monitoring endpoint**

```php
<?php
// app/Http/Controllers/LoadBalancerMonitorController.php

namespace App\Http\Controllers;

use App\Services\LoadBalancerService;
use Illuminate\Http\JsonResponse;

class LoadBalancerMonitorController extends Controller
{
    public function __construct(private LoadBalancerService $loadBalancer) {}

    /**
     * Shows current queue depths across all servers.
     * Use this to verify distribution is working correctly.
     */
    public function status(): JsonResponse
    {
        return response()->json([
            'queue_lengths' => $this->loadBalancer->getQueueLengths(),
            'strategy'      => 'Weighted Round-Robin',
        ]);
    }
}
```

```php
// routes/api.php
Route::get('/load-balancer/status', [LoadBalancerMonitorController::class, 'status'])
     ->middleware('auth:sanctum');
```

**Step 6: Start workers for each simulated server**

```bash
# Each command simulates a separate server's worker pool
php artisan queue:work --connection=server_1 --queue=server_1_orders --sleep=3 &
php artisan queue:work --connection=server_2 --queue=server_2_orders --sleep=3 &
php artisan queue:work --connection=server_3 --queue=server_3_orders --sleep=3 &
```

---

### 4. Explanation of the Fix

| Component | Role |
|---|---|
| `config/load_balancer.php` | Declares servers with weights — the "capacity declaration" of each node |
| `buildWeightedPool()` | Expands weights into a flat pool: `[s1,s1,s1,s2,s2,s3]` for weights `[3,2,1]` |
| `Redis::incr($counterKey)` | Atomic increment — the pointer that moves around the pool with each request |
| `% count($pool)` | Wraps the counter around the pool size |
| `connectionByAffinity()` | Consistent hash for session stickiness (same user → same server) |
| `getQueueLengths()` | Observability — verify that distribution is working as expected |

**Distribution result with weights `[3, 2, 1]`:**
```
Request  1 → server_1
Request  2 → server_1
Request  3 → server_1
Request  4 → server_2
Request  5 → server_2
Request  6 → server_3
Request  7 → server_1  ← wraps around
...
```

---

### 5. Why This Approach Is the Best

- **Weighted distribution** — more powerful servers handle proportionally more traffic. Simple Round-Robin ignores server capacity differences.
- **Atomic counter** — `Redis::incr()` is an atomic operation; no race condition on the counter itself.
- **Affinity support** — the same service provides session-sticky routing for stateful operations.
- **Zero external tools needed** — fully implemented in Laravel/Redis with no Nginx or external load balancer required for simulation.
- **Observable** — the `/load-balancer/status` endpoint makes it easy to verify distribution during stress testing.

---

### 6. Two Alternative Solutions

**Alternative A — Simple Round-Robin (No Weights)**

```php
// Simpler — all servers equal
public function nextConnection(): string
{
    $servers    = config('load_balancer.servers');
    $index      = Redis::incr($this->counterKey) % count($servers);
    return $servers[$index]['connection'];
}
```

**Alternative B — Least-Connections Strategy**

```php
// Routes to the server with the shortest queue (most idle)
public function leastLoadedConnection(): string
{
    $servers = config('load_balancer.servers');

    return collect($servers)
        ->sortBy(fn($server) => Redis::llen($server['queue']))
        ->first()['connection'];
}
```

---

### 7. Why the Alternatives Are Less Suitable

| Alternative | Weakness |
|---|---|
| **A — Simple Round-Robin** | Ignores server capacity differences. A 2-core server receives the same traffic as an 8-core server → smaller server overwhelmed. Works only when all servers are identical. |
| **B — Least-Connections** | Requires reading queue lengths on **every request** — 3 Redis reads per dispatch instead of 1. Under high traffic this creates Redis latency overhead. Also, queue length is not always an accurate proxy for server load (a queue can be short but with very long-running jobs). |

**Weighted Round-Robin** achieves the right balance: it respects server capacity without the per-request overhead of dynamic measurements.

---

### 8. Why the Problem Happened

The original design implicitly assumed a single-server environment — all routes, jobs, and database connections were configured for one queue. As traffic grows, the single queue becomes a bottleneck: more jobs pile up than the single set of workers can drain. The system was designed for convenience, not for horizontal scale.

---

## Summary Table

| Task | Laravel Mechanism | Key Concept |
|---|---|---|
| 1 — Race Condition | `DB::transaction()` + `lockForUpdate()` | Pessimistic locking — row-level exclusive lock |
| 2 — Capacity Control | `RateLimiter` + Redis Semaphore + Supervisor | Multi-layer throttling — HTTP, queue, execution |
| 3 — Async Queues | `ShouldQueue` + `dispatch()` + `after_commit` | Fire-and-forget — decouple response from processing |
| 4 — Batch Processing | `chunk()` + `Bus::batch()` | Memory-bounded parallel chunking |
| 5 — Load Distribution | Weighted Round-Robin + Redis INCR | Atomic counter + weighted server pool |
