<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\ProductController;
use App\Http\Controllers\API\CategoryController;
use App\Http\Controllers\API\CartController;
use App\Http\Controllers\API\OrderController;
use App\Http\Controllers\API\PaymentController;
use App\Http\Controllers\API\Admin\InventoryController;
use App\Http\Controllers\API\Admin\AdminOrderController;
use App\Http\Controllers\API\Admin\AdminProductController;

/*
|--------------------------------------------------------------------------
| API Routes — E-Commerce Platform
|--------------------------------------------------------------------------
|
| Version: v1
| Auth: Laravel Sanctum (token-based)
|
*/

Route::prefix('v1')->group(function () {

    // ── Public: Health ──────────────────────────────────────────────────
    Route::get('/health', fn() => response()->json([
        'status'    => 'ok',
        'version'   => '1.0.0',
        'timestamp' => now()->toIso8601String(),
    ]));

    // ── Public: Auth ────────────────────────────────────────────────────
    Route::prefix('auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login',    [AuthController::class, 'login']);
        // Webhook: public (Stripe, etc.)
        Route::post('/payments/webhook', [PaymentController::class, 'webhook']);
    });

    // ── Public: Products & Categories ──────────────────────────────────
    Route::get('/categories',               [CategoryController::class, 'index']);
    Route::get('/categories/{id}',          [CategoryController::class, 'show']);
    Route::get('/categories/{id}/products', [CategoryController::class, 'products']);
    Route::get('/products',                 [ProductController::class, 'index']);
    Route::get('/products/{id}',            [ProductController::class, 'show']);

    // ── Authenticated Routes ────────────────────────────────────────────
    Route::middleware('auth:sanctum')->group(function () {

        // Auth
        Route::post('/auth/logout',  [AuthController::class, 'logout']);
        Route::get('/auth/me',       [AuthController::class, 'me']);
        Route::put('/auth/profile',  [AuthController::class, 'updateProfile']);
        Route::put('/auth/password', [AuthController::class, 'changePassword']);

        // Cart
        Route::prefix('cart')->group(function () {
            Route::get('/',             [CartController::class, 'index']);
            Route::post('/items',       [CartController::class, 'addItem']);
            Route::put('/items/{id}',   [CartController::class, 'updateItem']);
            Route::delete('/items/{id}',[CartController::class, 'removeItem']);
            Route::delete('/',          [CartController::class, 'clear']);
            Route::get('/summary',      [CartController::class, 'summary']);
        });

        // Orders
        Route::prefix('orders')->group(function () {
            Route::get('/',             [OrderController::class, 'index']);
            Route::post('/', [OrderController::class, 'store'])
                ->middleware('throttle.order');
            Route::get('/{id}',         [OrderController::class, 'show']);
            Route::post('/{id}/cancel', [OrderController::class, 'cancel']);
        });

        // Payments
        Route::prefix('payments')->group(function () {
            Route::post('/',     [PaymentController::class, 'process']);
            Route::get('/{id}',  [PaymentController::class, 'show']);
        });
    });

    // ── Admin Routes ────────────────────────────────────────────────────
    Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function () {

        // Products (CRUD)
        Route::apiResource('products', AdminProductController::class);

        // Inventory
        Route::get('/inventory',             [InventoryController::class, 'index']);
        Route::get('/inventory/{productId}', [InventoryController::class, 'show']);
        Route::put('/inventory/{productId}', [InventoryController::class, 'update']);
        Route::get('/inventory/low-stock',   [InventoryController::class, 'lowStock']);

        // Orders
        Route::get('/orders',              [AdminOrderController::class, 'index']);
        Route::get('/orders/{id}',         [AdminOrderController::class, 'show']);
        Route::put('/orders/{id}/status',  [AdminOrderController::class, 'updateStatus']);
        Route::get('/orders/stats',        [AdminOrderController::class, 'stats']);
    });
});
