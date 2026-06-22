# E-Commerce Backend Engine

> **High-performance, concurrent e-commerce backend** built with Laravel 11, demonstrating 10 parallel programming concepts.

## Tech Stack

| Component | Technology |
|-----------|-----------|
| Framework | Laravel 11 (PHP 8.3) |
| Database | SQLite (WAL mode) |
| Cache & Queues | Redis 7 |
| Web Server | Nginx 1.25 (load balancer) |
| Containers | Docker Compose (3 app instances) |
| Monitoring | Prometheus & Grafana |
| Load Testing | k6 (100 virtual users) |

## Architecture

```
Nginx (Load Balancer, port 8080)
├── app1 (Laravel + PHP-FPM, 20 workers)
├── app2 (Laravel + PHP-FPM, 20 workers)
└── app3 (Laravel + PHP-FPM, 20 workers)
    ├── Redis 7 (Cache + Queue + Semaphores)
    └── SQLite (WAL mode)
```

## Quick Start

```bash
# Build & start
docker compose up -d --build

# Seed database
docker compose exec app1 php artisan migrate:fresh --seed --force

# Run queue workers
docker compose exec app1 php artisan queue:work --queue=high,default &

# Health check
curl http://localhost:8080/api/v1/health
```

## 10 Parallel Programming Tasks

| # | Task | Key Solution | Result |
|---|------|-------------|--------|
| 1 | **Race Condition** | `lockForUpdate()` + `DB::transaction()` | 8/10 concurrent orders correctly blocked |
| 2 | **Rate Limiting** | 3-layer: Laravel throttle, Redis semaphores, Nginx | 429 responses for excess requests |
| 3 | **Async Queues** | Redis-backed queue workers | Response time dropped from 55ms → 22ms |
| 4 | **Batch Processing** | `chunk(500)` + `Bus::batch()` | Constant memory usage regardless of data size |
| 5 | **Load Balancing** | Nginx weighted round-robin (3:2:1) | Fault tolerance + auto-recovery |
| 6 | **Redis Caching** | Tag-based cache + stampede protection | Sub-100ms response for popular endpoints |
| 7 | **Concurrency Control** | Pessimistic + Optimistic locking | Correct strategy per operation context |
| 8 | **ACID Checkout** | Single atomic `POST /checkout` | Payment + stock + order all succeed or roll back |
| 9 | **k6 Stress Testing** | 100 VUs across 10 scenarios | 87.42% pass rate after 16 fixes |
| 10 | **Monitoring** | Prometheus + Grafana | 11+ dashboard panels for real-time metrics |

## API Endpoints

All endpoints are prefixed with `/api/v1`.

### Public
- `GET /health` — Health check
- `GET /products` — List products (cached)
- `GET /products/{id}` — Product detail (cached)
- `GET /categories` — List categories

### Auth (Sanctum tokens)
- `POST /auth/register` — Register
- `POST /auth/login` — Login
- `POST /auth/logout` — Logout
- `GET /auth/me` — Profile

### Authenticated
- `POST /cart/items` — Add to cart
- `GET /cart` — View cart
- `PUT /cart/items/{id}` — Update cart item
- `DELETE /cart/items/{id}` — Remove from cart
- `POST /orders` — Place order
- `GET /orders` — List orders
- `GET /orders/{id}` — Order detail
- `PUT /orders/{id}/cancel` — Cancel order
- `POST /checkout` — Atomic checkout (payment + stock + order)

### Admin
- `POST /admin/products` — Create product
- `PUT /admin/products/{id}` — Update product
- `DELETE /admin/products/{id}` — Delete product
- `GET /admin/orders` — All orders
- `PUT /admin/orders/{id}/status` — Update order status
- `GET /admin/orders/stats` — Order statistics
- `GET /admin/inventory/{productId}` — Inventory detail
- `PUT /admin/inventory/{productId}` — Update inventory
- `GET /admin/inventory/low-stock` — Low stock alerts

## Testing

```bash
# Run all tests
./scripts/run_all.sh

# Individual tests
./scripts/test_task1_race_condition.sh
./scripts/test_task2_rate_limiting.sh
./scripts/test_task3_async_queues.sh
./scripts/test_task4_batch_processing.sh
./scripts/test_task5_load_distribution.sh

# Toggle between old/new code
./scripts/toggle_code.sh <task> <old|new>

# k6 stress test
docker compose exec -T k6 k6 run k6/test-ecommerce.js
```

## Key Files

| File | Purpose |
|------|---------|
| `app/Services/OrderService.php` | Order logic with pessimistic locking |
| `app/Services/CheckoutService.php` | ACID checkout flow |
| `app/Services/CachedProductService.php` | Redis caching with stampede protection |
| `app/Services/SemaphoreService.php` | Redis-based semaphore for concurrency control |
| `app/Services/CartService.php` | Cart with stock reservation |
| `app/Jobs/ProcessOrder.php` | Async order processing |
| `app/Jobs/DispatchDailySalesBatchJob.php` | Chunked batch processing |
| `app/Http/Middleware/ThrottleOrders.php` | Rate limiting middleware |
| `app/Prometheus/` | Prometheus metrics collection |
| `deploy/nginx/load-balancer.conf` | Nginx load balancing config |
| `docker-compose.yml` | Multi-container orchestration |
| `k6/test-ecommerce.js` | k6 load test scenarios |

## Monitoring

- **Prometheus**: `http://localhost:9090`
- **Grafana**: `http://localhost:3000` (admin/admin)

## License

Project for Faculty of Informatics Engineering — Damascus University.
