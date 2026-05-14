# Testing Guide — Parallel Programming Tasks

This guide walks through how to test each of the 5 implemented tasks.

## Prerequisites (Docker)

```bash
# Build and start everything (3 app instances by default)
docker compose up -d

# Watch logs from all services
docker compose logs -f

# Check that all containers are running
docker compose ps
```

Expected output:
```
NAME                    IMAGE                   STATUS
ecommerce-nginx         nginx:1.25-alpine       Up
ecommerce-redis         redis:7-alpine          Up (healthy)
ecommerce-app-1         ecommerce-api:latest    Up
ecommerce-app-2         ecommerce-api:latest    Up
ecommerce-app-3         ecommerce-api:latest    Up
```

---

## Task 1 — Race Condition (Pessimistic Locking)

**What was implemented:**
- `InventoryService::decrementStock()` uses `lockForUpdate()` inside a `DB::transaction()`
- `InsufficientStockException` is thrown when stock is insufficient

**Test it manually:**

```bash
# 1. Register a user and get a token
TOKEN=$(curl -s -X POST http://localhost:8080/api/v1/auth/register \
  -H "Content-Type: application/json" \
  -d '{"name":"Test","email":"test@test.com","password":"password123","password_confirmation":"password123"}' \
  | jq -r '.data.token')

# 2. Check available products
curl -s http://localhost:8080/api/v1/products | jq '.data[0:2]'

# 3. Add a product to cart
curl -s -X POST http://localhost:8080/api/v1/cart/items \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"product_id":1,"quantity":1}' | jq .

# 4. Place an order
curl -s -X POST http://localhost:8080/api/v1/orders \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"shipping_address":{"name":"Test","street":"123 St","city":"City","country":"US","zip":"10001"}}' | jq .
```

**Simulate a race condition (concurrent orders):**

```bash
# Using a small script to fire 10 concurrent order attempts
# This would normally cause overselling — our lock prevents it
for i in $(seq 1 10); do
    curl -s -X POST http://localhost:8080/api/v1/orders \
      -H "Authorization: Bearer $TOKEN" \
      -H "Content-Type: application/json" \
      -d '{"shipping_address":{"name":"Test","street":"123 St","city":"City","country":"US","zip":"10001"}}' &
done
wait

# Verify stock never went negative
curl -s http://localhost:8080/api/v1/products/1 | jq '.data'
# Check inventory via admin endpoint or DB
```

**Expected behavior:**
- All orders complete without overselling
- Stock never drops below 0
- Failed orders return `422` with "Insufficient stock" error

---

## Task 2 — Resource Management & Capacity Control

**What was implemented:**
- Rate limiter `checkout`: 60 requests/minute/user
- Rate limiter `heavy-processing`: 10 requests/minute system-wide
- `SemaphoreService` (Redis Lua-based): limits concurrent job execution
- `ProcessOrder` job middleware: `WithoutOverlapping` + `ThrottlesExceptions`
- Queue config: `after_commit => true`
- Custom `ThrottleOrders` middleware: 10 orders/minute/user (already existed)

### Test Rate Limiting

```bash
# Rapidly fire 15+ order requests — should get 429 after 10
TOKEN="<your-token>"
for i in $(seq 1 15); do
    echo "Request $i:"
    curl -s -X POST http://localhost:8080/api/v1/orders \
      -H "Authorization: Bearer $TOKEN" \
      -H "Content-Type: application/json" \
      -d '{"shipping_address":{"name":"T","street":"S","city":"C","country":"US","zip":"1"}}' \
      | jq -c '{status: .status, message: .message, success: .success}'
done
```

**Expected:** Requests after the 10th return `429` with rate limit message.

### Test the Dedicated Rate Limiter

```bash
# Add a checkout route with the dedicated limiter to test
# (Currently the route uses `throttle.order` middleware)
```

### Test Redis Semaphore (via ProcessOrder job)

```bash
# Place 15+ orders rapidly — the semaphore limits concurrent ProcessOrder executions
for i in $(seq 1 20); do
    # Add to cart and order...
    # Each ProcessOrder job will try to acquire the semaphore
    # Only 10 can run concurrently; others are re-queued
done

# Check queue logs
docker compose logs app | grep "ProcessOrder"
```

**Expected:** At most 10 `ProcessOrder` jobs execute simultaneously.

---

## Task 3 — Asynchronous Queues

**What was implemented:**
- `GenerateInvoiceJob` → `invoices` queue (3 retries, 60s timeout)
- `SendOrderNotificationsJob` → `notifications` queue (5 retries, 30s backoff, 2s delay)
- `RecordSaleAnalyticsJob` → `analytics` queue (2 retries)
- `OrderService::placeOrder()` dispatches all 3 in parallel
- Supporting: `AnalyticsService`, `InvoiceService`, `OrderConfirmedNotification`

### Test Async Dispatch

```bash
# 1. Place an order
TOKEN="<your-token>"
curl -s -X POST http://localhost:8080/api/v1/orders \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"shipping_address":{"name":"Test","street":"123 St","city":"City","country":"US","zip":"10001"}}' | jq .

# 2. Check the logs — you should see all 3 jobs being processed
docker compose logs app | grep -E "(Invoice|Notification|Analytics)"
```

**Expected output in logs:**
```
Invoice: PDF generated for order #ORD-XXXXXXXX-20260514
Analytics: Sale recorded for order #ORD-XXXXXXXX-20260514, total: ...
Order confirmation sent for order #ORD-XXXXXXXX-20260514
```

### Test Queue Isolation

```bash
# Check that jobs are routed to correct queues
docker compose exec redis redis-cli --raw LLEN invoices
docker compose exec redis redis-cli --raw LLEN notifications
docker compose exec redis redis-cli --raw LLEN analytics
```

**Expected:** Each queue has its own jobs. Failure in one doesn't affect the others.

---

## Task 4 — Batch Processing

**What was implemented:**
- `DailySalesReport` model + migration
- `ProcessSalesChunkJob`: processes 500 orders at a time
- `DispatchDailySalesBatchJob`: orchestrator using `Bus::batch()`
- Scheduled at 02:00 and 14:00 daily

### Test Batch Processing

```bash
# 1. Generate test orders (you'll need existing products and users)
# Run the database seeder first if needed
docker compose exec app php artisan db:seed

# 2. Manually trigger the batch job (today's orders)
docker compose exec app php artisan tinker --execute="
    \App\Jobs\DispatchDailySalesBatchJob::dispatch(now()->toDateString());
    echo 'Batch job dispatched!\n';
"

# 3. Wait a few seconds, then check results
docker compose exec app php artisan tinker --execute="
    \$reports = \App\Models\DailySalesReport::all();
    \$reports->each(function(\$r) {
        echo \"Date: {\$r->date}, Revenue: {\$r->chunk_revenue}, Orders: {\$r->chunk_count}\n\";
    });
"

# 4. Check batch status
docker compose exec app php artisan tinker --execute="
    use Illuminate\Support\Facades\Bus;
    \$batches = DB::table('job_batches')->get();
    \$batches->each(function(\$b) {
        echo \"Batch: {\$b->name}, Total: {\$b->total_jobs}, Failed: {\$b->failed_jobs}\n\";
    });
"
```

**Expected:** Orders are chunked into groups of 500, processed in parallel, and results aggregated in `daily_sales_reports`.

### Test with Large Dataset

```bash
# Create 5000+ test orders to see chunking in action
docker compose exec app php artisan tinker --execute="
    \$user = \App\Models\User::first();
    \$product = \App\Models\Product::first();
    for(\$i = 0; \$i < 5000; \$i++) {
        \App\Models\Order::create([
            'user_id' => \$user->id,
            'order_number' => 'ORD-TEST-' . uniqid(),
            'status' => 'delivered',
            'subtotal' => 50,
            'tax' => 7.5,
            'shipping_fee' => 5,
            'total' => 62.5,
            'shipping_address' => json_encode(['name'=>'T','street'=>'S','city'=>'C','country'=>'US','zip'=>'1']),
            'created_at' => now(),
        ]);
    }
    echo 'Created 5000 test orders\n';
"

# Now dispatch the batch
docker compose exec app php artisan tinker --execute="
    \App\Jobs\DispatchDailySalesBatchJob::dispatch(now()->toDateString());
"
```

**Expected:** The batch creates `ceil(5000/500) = 10` chunk jobs that run in parallel.

---

## Task 5 — Load Distribution (Nginx)

**What was implemented:**
- `docker-compose.yml` with 3 app instances behind Nginx
- Weighted round-robin (weights 3:2:1 = 50% / 33% / 17%)
- Nginx config with proxy pass, rate limiting, security headers
- `X-Upstream` header shows which instance handled each request

### Test Load Balancing

```bash
# 1. Verify all app instances are registered
docker compose ps

# 2. Send requests through the load balancer — check X-Upstream header
for i in $(seq 1 12); do
    curl -sI http://localhost:8080/api/v1/health | grep -i "x-upstream"
done
```

**Expected output (weights 3:2:1 distribution):**
```
x-upstream: 172.18.0.4:8000
x-upstream: 172.18.0.4:8000
x-upstream: 172.18.0.4:8000
x-upstream: 172.18.0.5:8000
x-upstream: 172.18.0.5:8000
x-upstream: 172.18.0.6:8000
x-upstream: 172.18.0.4:8000
```

The pattern repeats `[3x app_1, 2x app_2, 1x app_3]`.

### Test Scaling Up

```bash
# Scale to 5 app instances
docker compose up -d --scale app=5 --no-recreate

# Update the Nginx config upstream servers (you'd need to reload)
# Or just test that all 5 are reachable
for i in $(seq 1 20); do
    curl -sI http://localhost:8080/api/v1/health | grep -i "x-upstream"
done | sort | uniq -c
```

### Test Fault Tolerance

```bash
# Stop one app instance
docker compose stop app-1

# Requests should still work — routed to remaining instances
curl -s http://localhost:8080/api/v1/health | jq .

# Check that the failed instance is removed from the pool
curl -sI http://localhost:8080/api/v1/health | grep -i "x-upstream"

# Restart it
docker compose start app-1
```

### Test Nginx Rate Limiting

```bash
# Fire rapid requests to see Nginx-level rate limiting
for i in $(seq 1 100); do
    curl -s -o /dev/null -w "%{http_code} " http://localhost:8080/api/v1/health
done
echo ""
```

**Expected:** After exceeding 60 req/s with burst=20, you'll see `503` responses.

---

## Full Integration Test

Run the project's own test suite inside the container:

```bash
# Run all feature tests
docker compose exec app php artisan test --testsuite=Feature

# Expected: 21 tests, 0 failures
```

## Monitoring Commands

```bash
# Watch all logs
docker compose logs -f

# Watch only Nginx access logs
docker compose logs -f nginx

# Check Redis queue lengths
docker compose exec redis redis-cli LLEN default
docker compose exec redis redis-cli LLEN invoices
docker compose exec redis redis-cli LLEN notifications
docker compose exec redis redis-cli LLEN analytics
docker compose exec redis redis-cli LLEN batch-processing

# Check the semaphore counter
docker compose exec redis redis-cli GET heavy_report_concurrent

# List all app containers
docker compose ps

# Check resource usage
docker stats
```
