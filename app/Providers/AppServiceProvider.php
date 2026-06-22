<?php

// ═══════════════════════════════════════════════════════════════════════
// BEFORE — Task 2: No Rate Limiters (Unlimited requests)
// ═══════════════════════════════════════════════════════════════════════
//
// Bad boot() — no RateLimiter::for() calls, unlimited traffic:
//
//  public function bootBad(): void
//  {
//      Event::listen(OrderPlaced::class, HandleOrderPlaced::class);
//      Event::listen(StockLow::class, NotifyAdminStockLow::class);
//      // ⚠ NO RateLimiter::for() calls at all!
//      // 1000 concurrent requests all pass through — server crash
//  }
//
// ═══════════════════════════════════════════════════════════════════════
// AFTER (current code): RateLimiter::for('api', 'orders', 'checkout', 'heavy-processing')
// ═══════════════════════════════════════════════════════════════════════


namespace App\Providers;

use App\Events\OrderPlaced;
use App\Events\StockLow;
use App\Listeners\HandleOrderPlaced;
use App\Listeners\NotifyAdminStockLow;
use App\Services\AnalyticsService;
use App\Services\AuthService;
use App\Services\CachedProductService;
use App\Services\CartService;
use App\Services\CheckoutService;
use App\Services\InventoryService;
use App\Services\InvoiceService;
use App\Services\OptimisticInventoryService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\SemaphoreService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\ServiceProvider;
use Spatie\Prometheus\Facades\Prometheus;
use Illuminate\Support\Facades\Redis;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {

        $this->app->singleton(AuthService::class);
        $this->app->singleton(InventoryService::class);
        $this->app->singleton(CartService::class);

        $this->app->singleton(OrderService::class, fn($app) => new OrderService(
            $app->make(CartService::class),
            $app->make(InventoryService::class),
        ));

        $this->app->singleton(SemaphoreService::class);
        $this->app->singleton(CachedProductService::class);
        $this->app->singleton(PaymentService::class);
        $this->app->singleton(OptimisticInventoryService::class);
        $this->app->singleton(CheckoutService::class);
        $this->app->singleton(AnalyticsService::class);
        $this->app->singleton(InvoiceService::class);
    }

    public function boot(): void
    {

        Prometheus::registerCollectorClasses(config('prometheus.collectors', []));

        $this->registerDbQueryTracking();

        Event::listen(OrderPlaced::class, HandleOrderPlaced::class);
        Event::listen(StockLow::class, NotifyAdminStockLow::class);

        $this->ensureCorrelationIdLogging();

        RateLimiter::for('api', fn(Request $request) =>
            Limit::perMinute(60)->by($request->user()?->id ?: $request->ip())
        );

        RateLimiter::for('login', fn(Request $request) =>
            Limit::perMinute(200)->by($request->input('email', $request->ip()))
        );

        RateLimiter::for('orders', fn(Request $request) =>
            Limit::perMinute(10)->by($request->user()?->id ?: $request->ip())
        );

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

        RateLimiter::for('heavy-processing', function (Request $request) {
            return Limit::perMinute(10)->by('system');
        });
    }

    private function registerDbQueryTracking(): void
    {
        DB::listen(function ($query) {

            $sql = strtoupper(trim($query->sql));
            $type = 'other';
            if (str_starts_with($sql, 'SELECT')) $type = 'select';
            elseif (str_starts_with($sql, 'INSERT')) $type = 'insert';
            elseif (str_starts_with($sql, 'UPDATE')) $type = 'update';
            elseif (str_starts_with($sql, 'DELETE')) $type = 'delete';

            try {
                Redis::hincrby('metrics:db_queries', "{$query->connectionName}|{$type}", 1);

                $durationMs = $query->time;
                if ($durationMs > 100) {
                    $table = $this->extractTableFromSql($sql);
                    Redis::hincrby('metrics:db_slow_queries', "{$query->connectionName}|{$table}", 1);
                }
            } catch (\Throwable $e) {

            }
        });

        \Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\TransactionBeginning::class, function ($event) {
            try {
                Redis::hincrby('metrics:db_connections', $event->connectionName, 1);
            } catch (\Throwable $e) {

            }
        });

        \Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\TransactionCommitted::class, function ($event) {
            try {
                Redis::hincrby('metrics:db_transactions', "{$event->connectionName}|committed", 1);
            } catch (\Throwable $e) {

            }
        });

        \Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\TransactionRolledBack::class, function ($event) {
            try {
                Redis::hincrby('metrics:db_transactions', "{$event->connectionName}|rolled_back", 1);
            } catch (\Throwable $e) {

            }
        });
    }

    private function extractTableFromSql(string $sql): string
    {
        $patterns = [
            '/FROM\s+`?(\w+)`?/i',
            '/UPDATE\s+`?(\w+)`?/i',
            '/INTO\s+`?(\w+)`?/i',
            '/JOIN\s+`?(\w+)`?/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $sql, $matches)) {
                return strtolower($matches[1]);
            }
        }

        return 'unknown';
    }

    private function ensureCorrelationIdLogging(): void
    {

    }
}
