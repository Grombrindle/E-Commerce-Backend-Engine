<?php

namespace App\Providers;

use App\Events\OrderPlaced;
use App\Events\StockLow;
use App\Listeners\HandleOrderPlaced;
use App\Listeners\NotifyAdminStockLow;
use App\Services\AuthService;
use App\Services\CartService;
use App\Services\InventoryService;
use App\Services\OrderService;
use App\Services\PaymentService;
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

        // OrderService needs CartService + InventoryService
        $this->app->singleton(OrderService::class, fn($app) => new OrderService(
            $app->make(CartService::class),
            $app->make(InventoryService::class),
        ));
    }

    public function boot(): void
    {
        // Event listeners
        Event::listen(OrderPlaced::class, HandleOrderPlaced::class);
        Event::listen(StockLow::class, NotifyAdminStockLow::class);

        // Rate limiters
        RateLimiter::for('api', fn(Request $request) =>
            Limit::perMinute(60)->by($request->user()?->id ?: $request->ip())
        );

        RateLimiter::for('orders', fn(Request $request) =>
            Limit::perMinute(10)->by($request->user()?->id ?: $request->ip())
        );
    }
}
