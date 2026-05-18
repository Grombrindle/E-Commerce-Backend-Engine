# E-Commerce API — Comprehensive Testing Guide

> **Postman / Bruno / Insomnia Compatible**
> Base URL (Docker): `http://localhost:8080/api/v1`
> Base URL (Local): `http://localhost:8000/api/v1`

---

## Table of Contents

1. [Setup & Authentication](#1-setup--authentication)
2. [Task 1 — Race Condition Testing](#2-task-1--race-condition-testing)
3. [Task 2 — Rate Limiting & Capacity Control](#3-task-2--rate-limiting--capacity-control)
4. [Task 3 — Async Queue Jobs](#4-task-3--async-queue-jobs)
5. [Task 4 — Batch Processing](#5-task-4--batch-processing)
6. [Task 5 — Load Distribution (Nginx)](#6-task-5--load-distribution-nginx)
7. [Cart — Inventory Reservation System](#7-cart--inventory-reservation-system)
8. [Appendix: Old Code vs New Code](#8-appendix-old-code-vs-new-code)

---

## 1. Setup & Authentication

### 1.1 Start the Docker Environment

```bash
# Start all 3 app instances + Nginx + Redis
docker compose up -d --build

# Check all services are running
docker compose ps

# Watch logs (optional)
docker compose logs -f
```

### 1.2 Register a New User

**Endpoint:** `POST /api/v1/auth/register`

```json
{
  "name": "Test User",
  "email": "test@example.com",
  "password": "password123",
  "password_confirmation": "password123"
}
```

**Expected Response (201):**
```json
{
  "success": true,
  "message": "User registered successfully.",
  "data": {
    "user": { "id": 1, "name": "Test User", "email": "test@example.com" },
    "token": "1|abc123..."
  }
}
```

**Save the `token` value** — you'll need it as a Bearer token for all authenticated requests.

### 1.3 Login (If Already Registered)

**Endpoint:** `POST /api/v1/auth/login`

```json
{
  "email": "test@example.com",
  "password": "password123"
}
```

### 1.4 Set Authorization Header

In Postman/Bruno/Insomnia, set:
```
Authorization: Bearer <your_token>
```

---

## 2. Task 1 — Race Condition Testing

### What It Tests

When two users try to buy the **same product at the same time**, the system should prevent overselling. The fix uses `DB::transaction()` + `lockForUpdate()` (pessimistic locking).

### 2.1 Check Initial Inventory

**Endpoint:** `GET /api/v1/products/{id}`

Look at the `inventory` object in the response:
```json
"inventory": {
  "quantity": 100,
  "reserved_quantity": 0,
  "available_quantity": 100
}
```

### 2.2 Add Product to Cart

**Endpoint:** `POST /api/v1/cart/items`

```json
{
  "product_id": 1,
  "quantity": 2
}
```

### 2.3 Place an Order

**Endpoint:** `POST /api/v1/orders`

```json
{
  "shipping_address": {
    "name": "John Doe",
    "street": "123 Test St",
    "city": "Test City",
    "country": "US",
    "zip": "10001"
  }
}
```

### 2.4 Verify Stock Deduction

Check `GET /api/v1/products/{id}` again. If you ordered 2 units with initial stock 100, you should see `quantity: 98`.

### 2.5 Simulate Concurrent Orders (Race Condition Test)

Use a tool like `curl` + `xargs` to fire simultaneous requests:

```bash
# Register and get tokens for 3 users first
# Then run this script (replace TOKEN1, TOKEN2, TOKEN3):

for i in 1 2 3; do
  curl -s -X POST http://localhost:8080/api/v1/orders \
    -H "Authorization: Bearer TOKEN${i}" \
    -H "Content-Type: application/json" \
    -d '{"shipping_address": {"name": "User '$i'", "street": "123 St", "city": "City", "country": "US", "zip": "10001"}}' &
done
wait
```

**Expected behavior with fix:** No orders should oversell — stock never goes below 0.

**To test the OLD (broken) code:** See [Appendix 8.1](#81-task-1--race-condition-bad-code).

---

## 3. Task 2 — Rate Limiting & Capacity Control

### What It Tests

The order endpoint is throttled to **10 requests per minute per user**. After exceeding this limit, the API returns HTTP 429.

### 3.1 Fast-Fire 15 Requests (Same User)

```bash
for i in $(seq 1 15); do
  echo "--- Request $i ---"
  curl -s -o /dev/null -w "HTTP %{http_code}" \
    -X POST http://localhost:8080/api/v1/orders \
    -H "Authorization: Bearer YOUR_TOKEN" \
    -H "Content-Type: application/json" \
    -d '{"shipping_address": {"name": "Test", "street": "123 St", "city": "City", "country": "US", "zip": "10001"}}'
  echo ""
done
```

**Expected result:**
- Requests 1–10: HTTP 201 (if cart has items) or HTTP 422 (empty cart)
- Requests 11–15: **HTTP 429** — `"Too Many Attempts."`

### 3.2 Test Different Rate Limiters

| Limiter | Endpoint | Limit |
|---------|----------|-------|
| `api` | All API routes | 60 req/min/user |
| `orders` | `POST /api/v1/orders` | 10 req/min/user |
| `checkout` | (reserved for `/checkout`) | 60 req/min/user |

The `orders` limiter is the strictest at **10 req/min/user** — this is what you'll hit first.

### 3.3 Test Queue Worker Concurrency

Each app container runs 5 queue workers for `default` queue and 2 for `heavy` queue (see `docker/supervisord.conf`). With 3 app instances, that's 15 default workers + 6 heavy workers total.

The `SemaphoreService` limits `ProcessOrder` job concurrency to **max 10 at a time** across all instances.

---

## 4. Task 3 — Async Queue Jobs

### What It Tests

After placing an order, the response returns immediately (~100ms). The heavy work (invoice, email, analytics) happens in background queue workers.

### 4.1 Place an Order and Check Dispatch

**Endpoint:** `POST /api/v1/orders`

```json
{
  "shipping_address": {
    "name": "John Doe",
    "street": "123 Test St",
    "city": "Test City",
    "country": "US",
    "zip": "10001"
  }
}
```

**Expected Response (201):**
```json
{
  "success": true,
  "message": "Order placed successfully.",
  "data": {
    "id": 1,
    "order_number": "ORD-20260518-000001",
    "status": "pending",
    "total": 124.99,
    ...
  }
}
```

The response comes back **instantly** — the async jobs are dispatched to Redis queues:

| Job | Queue | Delay |
|-----|-------|-------|
| `GenerateInvoiceJob` | `invoices` | None |
| `SendOrderNotificationsJob` | `notifications` | 2 seconds |
| `RecordSaleAnalyticsJob` | `analytics` | None |
| `ProcessOrder` (via event `OrderPlaced`) | `default` | None |

### 4.2 Verify Jobs Were Queued

Check Redis queue lengths:

```bash
# If Redis is running on your host
redis-cli -p 6379 LLEN queues:invoices
redis-cli -p 6379 LLEN queues:notifications
redis-cli -p 6379 LLEN queues:analytics

# Or check logs
docker compose logs app1 | grep "Job\|Queue"
```

### 4.3 Check Worker Logs

```bash
docker compose logs app1 | grep -E "Invoice|Notification|Analytics|ProcessOrder"
```

You should see something like:
```
Invoice generated for order #ORD-20260518-000001
Order confirmation sent for order #ORD-20260518-000001
Sale analytics recorded for order #ORD-20260518-000001
Processing order #ORD-20260518-000001
```

### 4.4 Test Job Retry on Failure

To simulate a failure, you can temporarily break the InvoiceService (see Appendix). When a job fails:
- `GenerateInvoiceJob`: retries 3 times, then logs error
- `SendOrderNotificationsJob`: retries 5 times with 30s backoff
- `RecordSaleAnalyticsJob`: retries 2 times

---

## 5. Task 4 — Batch Processing

### What It Tests

Processing large datasets using **chunked** reads and **parallel** batch jobs via `Bus::batch()`.

### 5.1 Generate Test Orders

Run the seeder or artisan command to generate a large number of orders:

```bash
docker compose exec app1 php artisan db:seed --class=DatabaseSeeder
```

Or create test orders manually via the API (repeat the order flow many times).

### 5.2 Manually Trigger Daily Sales Batch

```bash
docker compose exec app1 php artisan tinker --execute="\App\Jobs\DispatchDailySalesBatchJob::dispatchSync(now()->subDay()->toDateString())"
```

### 5.3 Check the Scheduled Jobs

The batch processing runs automatically:
- **2:00 AM**: Processes previous day's orders
- **2:00 PM**: Processes today's orders so far

```php
// routes/console.php
Schedule::job(new DispatchDailySalesBatchJob(now()->subDay()->toDateString()))
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->name('batch-daily-sales');
```

### 5.4 Verify Chunk Processing

The `DispatchDailySalesBatchJob` chunks orders in groups of 500 IDs and dispatches each chunk as a `ProcessSalesChunkJob` via `Bus::batch()`. Check logs:

```bash
docker compose logs app1 | grep "Daily sales batch"
```

---

## 6. Task 5 — Load Distribution (Nginx)

### What It Tests

Nginx distributes HTTP requests across 3 Laravel app instances using weighted round-robin (3:2:1).

### 6.1 Verify Nginx Is Running

```bash
curl -s http://localhost:8080/api/v1/health
```

**Expected:**
```json
{"status":"ok","version":"1.0.0","timestamp":"2026-05-18T12:00:00+00:00"}
```

### 6.2 Check Upstream Distribution Header

```bash
curl -sI http://localhost:8080/api/v1/health | grep -i x-upstream
```

You'll see which container handled the request:
```
x-upstream: 172.18.0.2:8000  (app1)
# or
x-upstream: 172.18.0.3:8000  (app2)
# or
x-upstream: 172.18.0.4:8000  (app3)
```

### 6.3 Verify Weighted Distribution

Send 12 requests and count which upstream handles each:

```bash
for i in $(seq 1 12); do
  curl -sI http://localhost:8080/api/v1/health | grep -i "x-upstream" | awk '{print $2}'
done
```

**Expected pattern (3:2:1 weights):**
```
172.18.0.2:8000  (app1 × 3)
172.18.0.2:8000
172.18.0.2:8000
172.18.0.3:8000  (app2 × 2)
172.18.0.3:8000
172.18.0.4:8000  (app3 × 1)
172.18.0.2:8000  (wraps around)
172.18.0.2:8000
172.18.0.2:8000
172.18.0.3:8000
172.18.0.3:8000
172.18.0.4:8000
```

### 6.4 Test Fault Tolerance

```bash
# Kill one app instance
docker compose stop app2

# Try requests — they should all succeed (handled by app1 + app3)
for i in $(seq 1 6); do
  curl -sI http://localhost:8080/api/v1/health | grep -i "x-upstream"
done

# You should NOT see app2 (172.18.0.3) in the output
# Nginx automatically routes around the failed server

# Restart it
docker compose start app2
```

### 6.5 Test the OLD (Bad) Single-Server Setup

To compare, you can simulate the old setup by running just one instance:

```bash
# Stop the full stack
docker compose down

# Create a minimal docker-compose with just 1 app:
docker compose run --rm -p 8000:8000 app1 php artisan serve --host=0.0.0.0 --port=8000

# Now try stopping it — the API goes completely down
# No fault tolerance, no load distribution
```

---

## 7. Cart — Inventory Reservation System

### What It Tests

The new inventory reservation system ensures stock is **reserved when added to cart** and **released when items are removed or the order is placed**.

### 7.1 Check Initial Inventory

**Endpoint:** `GET /api/v1/products/{id}`

Note the values:
```
quantity: 100
reserved_quantity: 0
available_quantity: 100  (quantity - reserved)
```

### 7.2 Add Item to Cart (Reserves Stock)

**Endpoint:** `POST /api/v1/cart/items`

```json
{
  "product_id": 1,
  "quantity": 3
}
```

### 7.3 Verify Reservation

Check `GET /api/v1/products/{id}` again:
```
quantity: 100
reserved_quantity: 3      ← increased by 3!
available_quantity: 97
```

The product listing (`GET /api/v1/products`) should still show this product as "in stock" because `available_quantity > 0`.

### 7.4 Try to Add More Than Available

```json
{
  "product_id": 1,
  "quantity": 100
}
```

**Expected: HTTP 422** — `"Product '...' does not have enough stock."`

### 7.5 Update Item Quantity (Adjusts Reservation)

**Endpoint:** `PUT /api/v1/cart/items/{itemId}`

```json
{
  "quantity": 5
}
```

Check product again:
```
reserved_quantity: 5      ← increased from 3 to 5
available_quantity: 95
```

### 7.6 Remove Item (Releases Reservation)

**Endpoint:** `DELETE /api/v1/cart/items/{itemId}`

Check product again:
```
reserved_quantity: 0      ← released!
available_quantity: 100
```

### 7.7 Place Order (Deducts Stock + Releases Reservation)

After placing an order:
1. Stock is decremented by the ordered quantity (e.g., `100 → 98`)
2. Reserved quantity is released (e.g., `5 → 0`)
3. Cart items are deleted

**Result:** `available_quantity = 98 - 0 = 98` (correct final state)

### 7.8 Cancel Order (Restores Stock)

**Endpoint:** `POST /api/v1/orders/{orderId}/cancel`

```json
{
  "reason": "Changed my mind"
}
```

Check product — stock should be restored to original value.

---

## 8. Appendix: Old Code vs New Code

### 8.1 Task 1 — Race Condition (Bad Code)

To test the **old broken version** (no transaction, no lock):

**In `app/Services/InventoryService.php`:**
```php
// ⚠ BAD CODE — Comment out the AFTER code, uncomment this:
// public function decrementStock(int $productId, int $quantity): Inventory
// {
//     $inventory = Inventory::where('product_id', $productId)->first();  // NO LOCK
//     $available = $inventory->quantity - $inventory->reserved_quantity;
//     if ($available < $quantity) {
//         throw new InsufficientStockException(...);
//     }
//     $inventory->quantity -= $quantity;  // PHP arithmetic — NOT atomic!
//     $inventory->save();
//     return $inventory;
// }
```

**In `app/Services/OrderService.php`:**
```php
// ⚠ BAD CODE — Remove DB::transaction() wrapper:
// public function placeOrder(...)
// {
//     // NO TRANSACTION — partial failures leave data inconsistent!
//     foreach ($cart->activeItems as $item) {
//         $this->inventoryService->decrementStock(...);  // (using bad version above)
//     }
//     $order = Order::create([...]);
//     // ⚠ If this fails, stock is already decremented — NO ROLLBACK!
//     return $order;
// }
```

### 8.2 Task 1 — Race Condition (Good Code — Current)

```php
// ✅ GOOD CODE — Currently active:
public function decrementStock(int $productId, int $quantity): Inventory
{
    $inventory = Inventory::where('product_id', $productId)
        ->lockForUpdate()  // ✅ Pessimistic lock!
        ->firstOrFail();
    
    $available = $inventory->quantity - $inventory->reserved_quantity;
    if ($available < $quantity) {
        throw new InsufficientStockException(...);
    }
    
    $inventory->decrement('quantity', $quantity);  // ✅ Atomic SQL!
    return $inventory;
}
```

### 8.3 Task 2 — Rate Limiting (Bad Code)

**In `app/Providers/AppServiceProvider.php`:**
```php
// ⚠ BAD CODE — Remove all RateLimiter::for() calls:
// public function boot(): void
// {
//     Event::listen(OrderPlaced::class, HandleOrderPlaced::class);
//     // ⚠ NO RateLimiter calls — all routes unprotected!
//     // 1000 concurrent requests all pass through
// }
```

### 8.4 Task 2 — Rate Limiting (Good Code — Current)

```php
// ✅ GOOD CODE — Currently active:
RateLimiter::for('orders', fn(Request $request) =>
    Limit::perMinute(10)->by($request->user()?->id ?: $request->ip())
);

RateLimiter::for('checkout', function (Request $request) {
    return Limit::perMinute(60)
        ->by($request->user()?->id ?: $request->ip())
        ->response(function () {
            return response()->json(['error' => 'Too many requests.'], 429);
        });
});
```

### 8.5 Task 3 — Synchronous (Bad Code)

**In `app/Services/OrderService.php`:**
```php
// ⚠ BAD CODE — Replace dispatch calls with synchronous calls:
// $this->invoiceService->generate($order);       // ~1.5s BLOCKING
// $this->notificationService->sendEmail($order);  // ~1.0s BLOCKING
// $this->analyticsService->recordSale($order);    // ~0.5s BLOCKING
// Total response time: ~3 seconds!
```

### 8.6 Task 3 — Async (Good Code — Current)

```php
// ✅ GOOD CODE — Currently active:
GenerateInvoiceJob::dispatch($order)->onQueue('invoices');
SendOrderNotificationsJob::dispatch($order)
    ->onQueue('notifications')
    ->delay(now()->addSeconds(2));
RecordSaleAnalyticsJob::dispatch($order)->onQueue('analytics');
event(new OrderPlaced($order));
```

### 8.7 CartService — Old vs New (Inventory Reservation)

**Before (Old CartService):**
```php
// ⚠ No InventoryService — no stock reservation!
public function addItem(User $user, int $productId, int $quantity): CartItem
{
    $product = Product::active()->with('inventory')->findOrFail($productId);
    
    if ($product->inventory->quantity < $quantity) {
        throw new \RuntimeException("Not enough stock.");
    }
    
    // ⚠ Directly creates cart item without reserving stock
    return CartItem::create([
        'cart_id' => $cart->id,
        'product_id' => $productId,
        'quantity' => $quantity,
        'price' => $product->price,
    ]);
}

// ⚠ removeItem just deletes — no reservation release
public function removeItem(User $user, int $itemId): void
{
    CartItem::where('cart_id', $cart->id)->where('id', $itemId)->delete();
}

// ⚠ clearCart just deletes all — no reservation release
public function clearCart(User $user): void
{
    $cart->items()->delete();
}
```

**After (New CartService — Current):**
```php
// ✅ Injects InventoryService
public function __construct(protected InventoryService $inventoryService) {}

// ✅ Reserves stock when adding to cart
public function addItem(...): CartItem
{
    return DB::transaction(function () use ($cart, $productId, $quantity, $product) {
        $this->inventoryService->reserveStock($productId, $quantity);
        return CartItem::create([...]);
    });
}

// ✅ Adjusts reservation on quantity change
public function updateItem(...): CartItem
{
    DB::transaction(function () use ($item, $oldQty, $quantity) {
        $diff = $quantity - $oldQty;
        if ($diff > 0) {
            $this->inventoryService->reserveStock($item->product_id, $diff);
        } elseif ($diff < 0) {
            $this->inventoryService->releaseReservation($item->product_id, abs($diff));
        }
        $item->update(['quantity' => $quantity]);
    });
}

// ✅ Releases reservation on item removal
public function removeItem(...): void
{
    DB::transaction(function () use ($item) {
        $this->inventoryService->releaseReservation($item->product_id, $item->quantity);
        $item->delete();
    });
}

// ✅ Releases ALL reservations on cart clear
public function clearCart(...): void
{
    DB::transaction(function () use ($cart) {
        foreach ($cart->items as $item) {
            $this->inventoryService->releaseReservation($item->product_id, $item->quantity);
        }
        $cart->items()->delete();
    });
}
```

### 8.8 OrderService — Old vs New (Reservation Release)

**Before (Old OrderService):**
```php
// ⚠ Decremented stock but NEVER released the reservation!
foreach ($cart->activeItems as $item) {
    $this->inventoryService->decrementStock($item->product_id, $item->quantity);
    // ⚠ reserved_quantity stays at whatever CartService set it to
    // Next time someone adds to cart, available = quantity - reserved (incorrect)
}
```

**After (New OrderService — Current):**
```php
// ✅ Releases reservation AFTER decrementing stock
foreach ($cart->activeItems as $item) {
    $this->inventoryService->decrementStock($item->product_id, $item->quantity);
    $this->inventoryService->releaseReservation($item->product_id, $item->quantity);
    // reserved_quantity → 0 after order placement
}
```

### 8.9 Product Model — Old vs New (`isAvailable`)

**Before:**
```php
public function isAvailable(int $qty = 1): bool
{
    return $this->inventory && $this->inventory->quantity >= $qty;
    // ⚠ Does NOT consider reserved_quantity!
}
```

**After (Current):**
```php
public function isAvailable(int $qty = 1): bool
{
    if (!$this->inventory) return false;
    $available = $this->inventory->quantity - $this->inventory->reserved_quantity;
    return $available >= $qty;
    // ✅ Considers reservations — prevents overselling reserved stock
}
```

### 8.10 CartController — Added Cache Invalidation

**Before (Old CartController):**
```php
// ⚠ No cache invalidation after cart changes
public function addItem(AddToCartRequest $request)
{
    $item = $this->cartService->addItem(...);
    return $this->created($item->load('product'), 'Item added to cart.');
}
```

**After (New CartController — Current):**
```php
// ✅ Flushes product caches after cart changes
public function addItem(AddToCartRequest $request)
{
    $item = $this->cartService->addItem(...);
    Cache::tags(['products'])->flush();  // ✅ NEW
    return $this->created($item->load('product'), 'Item added to cart.');
}
```

### 8.11 OrderController — Added Cache Invalidation

```php
// ✅ Added after store() and cancel()
Cache::tags(['products'])->flush();
```

---

## Quick Reference: Old ↔ New Toggle Table

| Feature | Old Code (Comment Out) | New Code (Active) | File |
|---------|----------------------|-------------------|------|
| **Task 1: Stock Lock** | `Inventory::where(...)->first()` | `->lockForUpdate()->firstOrFail()` | `InventoryService.php` |
| **Task 1: Transaction** | No `DB::transaction()` | `DB::transaction()` wrapping all ops | `OrderService.php` |
| **Task 2: Rate Limiting** | No `RateLimiter::for()` calls | 4 rate limiters registered | `AppServiceProvider.php` |
| **Task 2: Semaphore** | No acquire/release | `SemaphoreService` with Lua scripts | `ProcessOrder.php` |
| **Task 3: Async Jobs** | Direct service calls | `dispatch()` to Redis queues | `OrderService.php` |
| **Task 4: Chunk Processing** | `Order::get()` (all in memory) | `chunk()` + `Bus::batch()` | `DispatchDailySalesBatchJob.php` |
| **Task 5: Load Balancing** | Single server (no nginx) | Nginx upstream 3:2:1 | `docker-compose.yml` |
| **Cart: Reservation** | No reservation on add | `reserveStock()` on add | `CartService.php` |
| **Cart: Quantity Update** | Direct quantity change | Adjusts reservation on change | `CartService.php` |
| **Cart: Remove/Clear** | Delete without releasing | Release reservation + delete | `CartService.php` |
| **Order: Reservation Release** | Never released reserved_quantity | `releaseReservation()` after decrement | `OrderService.php` |
| **Product: isAvailable** | Checked `quantity` only | Checked `quantity - reserved_quantity` | `Product.php` |
| **Cache: Product Cache** | No invalidation | `Cache::tags(['products'])->flush()` | `CartController.php`, `OrderController.php` |
