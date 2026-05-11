# 🛒 EcommerceAPI — Laravel 11

> منظومة تجارة إلكترونية عالية الأداء تعتمد على Laravel 11 مع دعم آلاف الطلبات المتزامنة.

---

## ✅ المميزات الرئيسية

| الميزة | التقنية |
|---|---|
| تسجيل/تسجيل دخول | Laravel Sanctum (tokens) |
| عرض المنتجات | Cache + Pagination + Filters |
| إدارة السلة | CartService + Stock Validation |
| إدارة المخزون | Pessimistic Locking (lockForUpdate) |
| إتمام الطلب | DB Transaction + Atomic Decrement |
| الدفع | PaymentService (Stripe-ready) |
| المعالجة غير المتزامنة | Queue Jobs |
| حماية من الفيضان | RateLimiter (10 orders/min) |
| صلاحيات المدير | AdminMiddleware |

---

## 🚀 تثبيت المشروع

```bash
# 1. نسخ ملف البيئة
cp .env.example .env

# 2. تثبيت الحزم
composer install

# 3. توليد مفتاح التشفير
php artisan key:generate

# 4. إعداد قاعدة البيانات
php artisan migrate

# 5. إدخال بيانات اختبارية
php artisan db:seed

# 6. تشغيل الخادم
php artisan serve
```

---

## 📡 API Endpoints

### Auth
| Method | Endpoint | Description | Auth |
|--------|----------|-------------|------|
| POST | `/api/v1/auth/register` | Register new user | ❌ |
| POST | `/api/v1/auth/login` | Login | ❌ |
| POST | `/api/v1/auth/logout` | Logout (revoke token) | ✅ |
| GET  | `/api/v1/auth/me` | Get profile | ✅ |
| PUT  | `/api/v1/auth/profile` | Update profile | ✅ |
| PUT  | `/api/v1/auth/password` | Change password | ✅ |

### Products
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/products` | List products (search, filter, sort, paginate) |
| GET | `/api/v1/products/{id}` | Product detail |
| GET | `/api/v1/categories` | All categories |
| GET | `/api/v1/categories/{id}/products` | Category products |

**Product Filters:** `?search=`, `?category=`, `?min_price=`, `?max_price=`, `?sort=price_asc|price_desc|newest|name`, `?in_stock=1`, `?per_page=20`

### Cart
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET    | `/api/v1/cart` | View cart |
| GET    | `/api/v1/cart/summary` | Totals summary |
| POST   | `/api/v1/cart/items` | Add item |
| PUT    | `/api/v1/cart/items/{id}` | Update quantity |
| DELETE | `/api/v1/cart/items/{id}` | Remove item |
| DELETE | `/api/v1/cart` | Clear cart |

### Orders
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET  | `/api/v1/orders` | My orders |
| POST | `/api/v1/orders` | Place order (rate limited: 10/min) |
| GET  | `/api/v1/orders/{id}` | Order detail |
| POST | `/api/v1/orders/{id}/cancel` | Cancel order |

### Payments
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/v1/payments` | Process payment |
| GET  | `/api/v1/payments/{id}` | Payment detail |
| POST | `/api/v1/payments/webhook` | Gateway webhook |

### Admin (role=admin required)
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET/POST | `/api/v1/admin/products` | List/Create products |
| GET/PUT/DELETE | `/api/v1/admin/products/{id}` | Manage product |
| GET | `/api/v1/admin/inventory` | All inventory |
| PUT | `/api/v1/admin/inventory/{productId}` | Update stock |
| GET | `/api/v1/admin/inventory/low-stock` | Low stock items |
| GET | `/api/v1/admin/orders` | All orders |
| PUT | `/api/v1/admin/orders/{id}/status` | Update status |
| GET | `/api/v1/admin/orders/stats` | Dashboard stats |

---

## 🔐 Authentication

```bash
# Register
curl -X POST http://localhost:8000/api/v1/auth/register \
  -H "Content-Type: application/json" \
  -d '{"name":"Ahmed","email":"ahmed@test.com","password":"secret123","password_confirmation":"secret123"}'

# Login
curl -X POST http://localhost:8000/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"ahmed@test.com","password":"secret123"}'

# Use token
curl http://localhost:8000/api/v1/cart \
  -H "Authorization: Bearer YOUR_TOKEN_HERE"
```

---

## 🔁 Test Accounts (after seeding)

| Email | Password | Role |
|-------|----------|------|
| admin@ecommerce.test | password | admin |
| customer@ecommerce.test | password | customer |

---

## 🧪 Run Tests

```bash
php artisan test
# or verbose
php artisan test --testdox
```

---

## ⚡ High-Concurrency Design

```
Request → Controller → Service → DB Transaction
                                   ├── SELECT ... FOR UPDATE  (Lock inventory row)
                                   ├── Validate stock
                                   ├── Decrement quantity
                                   ├── Create Order + Items
                                   └── Clear Cart
                              ↓ (after commit)
                         Dispatch Queue Job (async)
                              ├── Send confirmation email
                              └── Notify fulfillment
```

**Key techniques:**
- `lockForUpdate()` prevents overselling under concurrent requests
- `DB::transaction()` ensures atomicity
- Redis cache for product listings (5min TTL)
- Queue jobs for heavy async operations
- `ThrottleOrders` middleware: 10 orders/min per user

---

## 📂 Project Structure

```
app/
├── Http/
│   ├── Controllers/API/          # REST controllers
│   │   ├── Admin/                # Admin-only endpoints
│   │   ├── AuthController.php
│   │   ├── ProductController.php
│   │   ├── CartController.php
│   │   ├── OrderController.php
│   │   └── PaymentController.php
│   ├── Middleware/               # Auth, Admin, ThrottleOrders
│   └── Requests/                 # Form validation
├── Models/                       # Eloquent models + relationships
├── Services/                     # Business logic layer
│   ├── AuthService.php
│   ├── CartService.php
│   ├── OrderService.php          # ⭐ Core checkout logic
│   ├── PaymentService.php
│   └── InventoryService.php      # ⭐ Thread-safe stock management
├── Jobs/ProcessOrder.php         # Async order processing
└── Events/                       # Domain events
database/
├── migrations/                   # 10 migration files
├── seeders/                      # Test data
└── factories/                    # Fake data generators
tests/Feature/                    # 4 test suites
routes/api.php                    # All API routes
```

---

## 🛠️ Queue Worker

```bash
# Start queue worker (for async order processing)
php artisan queue:work --queue=default --tries=3
```

---

## 📦 Payment Testing

Use card ending in `0002` to simulate failure:
- `4242424242424242` → Success
- `4111111111110002` → Declined

