<?php

/* ============================================================
 * ╔══════════════════════════════════════════════════════════════╗
 * ║  BEFORE — Task 2: No Rate Limiting (The Problem)            ║
 * ╚══════════════════════════════════════════════════════════════╝
 *
 * The original boot() method had NO rate limiters at all.
 * 1000 simultaneous checkout requests all hit the server
 * at once — connection pool exhausted, server crashes.
 *
 *          Bad code (no rate limiters):
 *
 *          public function boot(): void
 *          {
 *              Event::listen(OrderPlaced::class, HandleOrderPlaced::class);
 *              Event::listen(StockLow::class, NotifyAdminStockLow::class);
 *
 *              // ⚠ NO RateLimiter::for() calls at all!
 *              // All routes are unprotected — unlimited requests
 *              // 1000 concurrent /checkout requests all pass through
 *          }
 *
 * What went wrong:
 *  - No API throttle → unlimited requests overwhelm PHP-FPM
 *  - No checkout throttle → flash sales crash the server
 *  - No heavy-processing throttle → compute-intensive routes
 *    consume all CPU without any cap
 *
 * ╔══════════════════════════════════════════════════════════════╗
 * ║  AFTER — Task 2: Multi-Layer Throttling (Fix)               ║
 * ╚══════════════════════════════════════════════════════════════╝
 *
 *          ✅ RateLimiter::for('api')         60 req/min/user
 *          ✅ RateLimiter::for('orders')      10 req/min/user
 *          ✅ RateLimiter::for('checkout')    60 req/min/user
 *          ✅ RateLimiter::for('heavy-processing') 10 req/min/system
 *          ✅ SemaphoreService for queue-level concurrency
 *
 * To test the bad code:
 *   1. Comment out all RateLimiter::for() calls in boot()
 *   2. Send 100 rapid requests to /checkout — all pass through
 *   3. Restore the code — after ~10-60 requests, see 429 errors
 * ============================================================ */

namespace App\Providers;

use App\Events\OrderPlaced;
use App\Events\StockLow;
use App\Listeners\HandleOrderPlaced;
use App\Listeners\NotifyAdminStockLow;
use App\Services\AnalyticsService;
use App\Services\AuthService;
use App\Services\CartService;
use App\Services\InventoryService;
use App\Services\InvoiceService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\SemaphoreService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind services as singletons
        $this->app->singleton(AuthService::class);
        $this->app->singleton(InventoryService::class);
        $this->app->singleton(CartService::class);
        $this->app->singleton(PaymentService::class);

        $this->app->singleton(OrderService::class, fn($app) => new OrderService(
            $app->make(CartService::class),
            $app->make(InventoryService::class),
        ));

        $this->app->singleton(SemaphoreService::class);
        $this->app->singleton(AnalyticsService::class);
        $this->app->singleton(InvoiceService::class);
    }

    public function boot(): void
    {
        Event::listen(OrderPlaced::class, HandleOrderPlaced::class);
        Event::listen(StockLow::class, NotifyAdminStockLow::class);

        // ── Rate Limiters ────────────────────────────────────
        RateLimiter::for('api', fn(Request $request) =>
            Limit::perMinute(60)->by($request->user()?->id ?: $request->ip())
        );

        RateLimiter::for('orders', fn(Request $request) =>
            Limit::perMinute(10)->by($request->user()?->id ?: $request->ip())
        );

        // Task 2: checkout — max 60 per minute per user
        RateLimiter::for('checkout', function (Request $request) {
            return Limit::perMinute(60)
                ->by($request->user()?->id ?: $request->ip())
                ->response(function () {
                    return response()->json([
                        'success' => false,
                        'error' => 'Too many requests. Please wait and try again.',
                    ], 429);
                });
        });

        // Task 2: heavy-processing — max 10 per minute system-wide
        RateLimiter::for('heavy-processing', function (Request $request) {
            return Limit::perMinute(10)->by('system');
        });
    }
}
