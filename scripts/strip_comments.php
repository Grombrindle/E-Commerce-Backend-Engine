<?php

$dryRun = in_array('--dry-run', $argv ?? []);
$verbose = in_array('--verbose', $argv ?? []);

$taskFiles = [

    'app/Services/OrderService.php' => [
        'name' => 'Task 1 & 3 — Race Condition + Async Queues',
        'bad_code' => <<<'PHP'
// ═══════════════════════════════════════════════════════════════════════
// BEFORE — Task 1: Race Condition (No transaction, no lock)
// BEFORE — Task 3: Synchronous Blocking (No async dispatch)
// ═══════════════════════════════════════════════════════════════════════
//
// Bad placeOrder() — no DB::transaction(), no lockForUpdate(), sync calls:
//
//  public function placeOrderBad(User $user, array $data): Order
//  {
//      $cart = Cart::where('user_id', $user->id)->with('activeItems')->first();
//      if (!$cart || $cart->isEmpty()) {
//          throw new RuntimeException('Cart is empty.');
//      }
//      // ⚠ TOCTOU: stock check and decrement are NOT atomic
//      foreach ($cart->activeItems as $item) {
//          $inventory = Inventory::where('product_id', $item->product_id)->first();
//          if ($inventory->quantity < $item->quantity) {
//              throw new RuntimeException('Insufficient stock');
//          }
//          $inventory->quantity -= $item->quantity;  // ⚠ Race window!
//          $inventory->save();
//      }
//      $order = Order::create([...]);
//      // ⚠ All sync — user blocks for ~3 seconds
//      $this->invoiceService->generate($order);
//      $this->sendOrderNotifications($order);
//      $this->recordSaleAnalytics($order);
//      return $order;
//  }
//
// ═══════════════════════════════════════════════════════════════════════
// AFTER (current code): DB::transaction() + lockForUpdate() + async dispatch
// ═══════════════════════════════════════════════════════════════════════

PHP
    ],

    'app/Models/Product.php' => [
        'name' => 'Task 1 & Cart — Race Condition + Reservations',
        'bad_code' => <<<'PHP'
// ═══════════════════════════════════════════════════════════════════════
// BEFORE — Task 1 & Cart: No Reservation Check (isAvailable checks quantity only)
// ═══════════════════════════════════════════════════════════════════════
//
// Bad isAvailable() — ignores reserved_quantity, allowing oversell:
//
//  public function isAvailableBad(int $qty = 1): bool
//  {
//      if (!$this->inventory) return false;
//      // ⚠ Only checks quantity, ignores reserved_quantity
//      // Two users could each reserve 5 of 10 total stock
//      return $this->inventory->quantity >= $qty;
//  }
//
// ═══════════════════════════════════════════════════════════════════════
// AFTER (current code): isAvailable() checks quantity - reserved_quantity
// ═══════════════════════════════════════════════════════════════════════

PHP
    ],

    'app/Services/CartService.php' => [
        'name' => 'Task 1 & Cart — No Stock Reservation',
        'bad_code' => <<<'PHP'
// ═══════════════════════════════════════════════════════════════════════
// BEFORE — Task 1 & Cart: No Stock Reservation
// ═══════════════════════════════════════════════════════════════════════
//
// Bad addItem() — does NOT reserve stock, items can be oversold:
//
//  public function addItemBad(User $user, int $productId, int $quantity): CartItem
//  {
//      $product = Product::active()->with('inventory')->findOrFail($productId);
//      if (!$product->isAvailable($quantity)) {
//          throw new RuntimeException('Not enough stock.');
//      }
//      $cart = $this->getOrCreateCart($user);
//      $existing = CartItem::where('cart_id', $cart->id)
//          ->where('product_id', $productId)->first();
//      if ($existing) {
//          // ⚠ No reservation adjustment — just updates quantity
//          $existing->update(['quantity' => $existing->quantity + $quantity]);
//          return $existing;
//      }
//      // ⚠ Creates cart item WITHOUT reserving stock
//      return CartItem::create([
//          'cart_id' => $cart->id,
//          'product_id' => $productId,
//          'quantity' => $quantity,
//          'price' => $product->price,
//      ]);
//  }
//
// ═══════════════════════════════════════════════════════════════════════
// AFTER (current code): reserveStock() + releaseReservation() in DB::transaction()
// ═══════════════════════════════════════════════════════════════════════

PHP
    ],

    'app/Providers/AppServiceProvider.php' => [
        'name' => 'Task 2 — No Rate Limiting',
        'bad_code' => <<<'PHP'
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

PHP
    ],

    'app/Jobs/DispatchDailySalesBatchJob.php' => [
        'name' => 'Task 4 — No Chunked Batch (Memory Exhaustion)',
        'bad_code' => <<<'PHP'
// ═══════════════════════════════════════════════════════════════════════
// BEFORE — Task 4: No Chunking (Memory Exhaustion)
// ═══════════════════════════════════════════════════════════════════════
//
// Bad handle() — loads ALL orders into memory at once:
//
//  public function handleBad(): void
//  {
//      // ⚠ get() hydrates ALL rows — 100K orders = ~200MB RAM
//      $orders = Order::whereDate('created_at', $this->date)->get();
//      foreach ($orders as $order) { /* process */ }
//      // ⚠ If OOM, no report is generated at all
//  }
//
// ═══════════════════════════════════════════════════════════════════════
// AFTER (current code): chunk(500) + Bus::batch() + allowFailures()
// ═══════════════════════════════════════════════════════════════════════

PHP
    ],

    'app/Http/Controllers/API/CartController.php' => [
        'name' => 'Cart Task — No Cache Flushing / Reservation',
        'bad_code' => <<<'PHP'
// ═══════════════════════════════════════════════════════════════════════
// BEFORE — Cart Task: No Cache Flush, No Reservation
// ═══════════════════════════════════════════════════════════════════════
//
// Bad addItem() — does NOT flush product cache or reserve stock:
//
//  public function addItemBad(AddToCartRequest $request)
//  {
//      try {
//          $item = $this->cartService->addItem(...);
//          // ⚠ NO CacheHelper::flush(['products']) — stale product lists
//          return $this->created($item->load('product'), 'Item added.');
//      } catch (RuntimeException $e) {
//          return $this->error($e->getMessage(), 422);
//      }
//  }
//
// ═══════════════════════════════════════════════════════════════════════
// AFTER (current code): CacheHelper::flush(['products']) after each mutation
// ═══════════════════════════════════════════════════════════════════════

PHP
    ],

    'app/Http/Controllers/API/OrderController.php' => [
        'name' => 'Task 1 & 2 & 3 — All Problems Combined',
        'bad_code' => <<<'PHP'
// ═══════════════════════════════════════════════════════════════════════
// BEFORE — Task 1 (Race) + Task 2 (No Throttle) + Task 3 (Sync)
// ═══════════════════════════════════════════════════════════════════════
//
// Bad store() — no transaction, no rate limit, sync calls:
//
//  public function storeBad(Request $request)
//  {
//      // ⚠ Task 2: No rate limiter — unlimited requests
//      $product = Product::find($request->product_id);
//      // ⚠ Task 1: TOCTOU — check and act are NOT atomic
//      if ($product->stock < $request->quantity) {
//          return response()->json(['error' => 'Insufficient stock'], 422);
//      }
//      $product->stock -= $request->quantity;
//      $product->save();
//      $order = Order::create([...]);
//      // ⚠ Task 3: All synchronous — user blocks for ~3s
//      $this->invoiceService->generate($order);
//      $this->sendOrderNotifications($order);
//      $this->analyticsService->recordSale($order);
//      return response()->json(['order' => $order], 201);
//  }
//
// ═══════════════════════════════════════════════════════════════════════
// AFTER (current code): OrderService with transaction + throttle:orders + async
// ═══════════════════════════════════════════════════════════════════════

PHP
    ],

    'app/Http/Controllers/API/ProductController.php' => [
        'name' => 'Task 6 — No Caching (Product List)',
        'bad_code' => <<<'PHP'
// ═══════════════════════════════════════════════════════════════════════
// BEFORE — Task 6: No Caching (Every request hits DB)
// ═══════════════════════════════════════════════════════════════════════
//
// Bad index() — no caching layer, every request queries DB:
//
//  public function indexBad(Request $request)
//  {
//      // ⚠ Every request hits the database, even identical queries
//      $products = Product::active()->with('category', 'inventory')
//          ->when($request->search, fn($q) => $q->search($request->search))
//          ->paginate($request->per_page ?? 20);
//      return $this->paginated($products);
//  }
//
// ═══════════════════════════════════════════════════════════════════════
// AFTER (current code): CachedProductService with tag-based caching + stampede prevention
// ═══════════════════════════════════════════════════════════════════════

PHP
    ],

    'app/Services/CachedProductService.php' => [
        'name' => 'Task 6 — No Caching (Direct DB queries)',
        'bad_code' => <<<'PHP'
// ═══════════════════════════════════════════════════════════════════════
// BEFORE — Task 6: No Caching (Every request queries DB directly)
// ═══════════════════════════════════════════════════════════════════════
//
// Bad getProductList() — no cache at all:
//
//  public function getProductListBad(Request $request)
//  {
//      // ⚠ No caching — 1000 req/s = 1000 DB queries
//      return Product::active()->with('category', 'inventory')
//          ->paginate($request->per_page ?? 20);
//  }
//
// ═══════════════════════════════════════════════════════════════════════
// AFTER (current code): Cache tags + stampede prevention + metrics
// ═══════════════════════════════════════════════════════════════════════

PHP
    ],

    'app/Services/OptimisticInventoryService.php' => [
        'name' => 'Task 7 — No Optimistic Locking (Lost Updates)',
        'bad_code' => <<<'PHP'
// ═══════════════════════════════════════════════════════════════════════
// BEFORE — Task 7: No Optimistic Locking (Lost Updates)
// ═══════════════════════════════════════════════════════════════════════
//
// Bad decrementStock() — no version check, concurrent updates lost:
//
//  public function decrementStockBad(int $productId, int $quantity): void
//  {
//      $inventory = Inventory::where('product_id', $productId)->first();
//      // ⚠ No WHERE version = ? check — last writer wins
//      // Two concurrent updates: both read same version, both write
//      // Last write overwrites the first — stock mismatch!
//      $inventory->quantity -= $quantity;
//      $inventory->save();
//  }
//
// ═══════════════════════════════════════════════════════════════════════
// AFTER (current code): WHERE version = ? + retry with exponential backoff
// ═══════════════════════════════════════════════════════════════════════

PHP
    ],

    'app/Services/CheckoutService.php' => [
        'name' => 'Task 8 — Two-Step Checkout (No ACID)',
        'bad_code' => <<<'PHP'
// ═══════════════════════════════════════════════════════════════════════
// BEFORE — Task 8: Two-Step Checkout (No Atomicity)
// ═══════════════════════════════════════════════════════════════════════
//
// Bad checkout() — two separate HTTP requests, no atomicity:
//
//  // Step 1: POST /api/v1/orders → creates order + decrements stock ✅
//  // Step 2: POST /api/v1/payments → processes payment ✅
//  // ⚠ Problem: If step 2 fails, stock is ALREADY decremented — no rollback!
//  // ⚠ Stock goes negative, order stays pending forever
//
// ═══════════════════════════════════════════════════════════════════════
// AFTER (current code): Single DB::transaction() with all-or-nothing atomicity
// ═══════════════════════════════════════════════════════════════════════

PHP
    ],
];

$skipPrefixes = [
    'vendor/',
    'storage/framework/',
    'storage/logs/',
    'bootstrap/cache/',
];

$projectRoot = __DIR__ . '/..';
$projectRoot = realpath($projectRoot);

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($projectRoot, RecursiveDirectoryIterator::SKIP_DOTS)
);

$phpFiles = [];
foreach ($iterator as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    $path = $file->getRealPath();
    $relativePath = str_replace($projectRoot . '/', '', $path);

    $skip = false;
    foreach ($skipPrefixes as $prefix) {
        if (str_starts_with($relativePath, $prefix)) {
            $skip = true;
            break;
        }
    }
    if ($skip) continue;

    $phpFiles[] = $relativePath;
}

sort($phpFiles);

echo "Found " . count($phpFiles) . " PHP files to process.\n";

$totalCommentsRemoved = 0;
$totalFilesWithChanges = 0;

foreach ($phpFiles as $relativePath) {
    $fullPath = $projectRoot . '/' . $relativePath;
    $content = file_get_contents($fullPath);

    if ($content === false) {
        echo "  ERROR: Could not read $relativePath\n";
        continue;
    }

    $tokens = @token_get_all($content);

    if ($tokens === false) {
        echo "  ERROR: Could not parse $relativePath\n";
        continue;
    }

    $newContent = '';
    $commentsRemoved = 0;
    $prevToken = null;

    foreach ($tokens as $token) {
        if (is_array($token)) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {

                $commentsRemoved++;
                $commentText = $token[1];

                if (substr_count($commentText, "\n") > 0) {

                    $newlines = substr_count($commentText, "\n");
                    $newContent .= str_repeat("\n", $newlines);
                }
                continue;
            }
            $newContent .= $token[1];
        } else {
            $newContent .= $token;
        }
    }

    $filesizeOriginal = strlen($content);
    $filesizeNew = strlen($newContent);

    if ($commentsRemoved > 0) {
        if ($verbose) {
            echo "  $relativePath: removed $commentsRemoved comments (" . ($filesizeOriginal - $filesizeNew) . " bytes)\n";
        }
        $totalCommentsRemoved += $commentsRemoved;
        $totalFilesWithChanges++;
    }

    if ($dryRun) {
        continue;
    }

    $badCodeToAdd = '';
    if (isset($taskFiles[$relativePath])) {
        $badCodeToAdd = $taskFiles[$relativePath]['bad_code'];
        if ($verbose) {
            echo "  >>> Adding bad version comment for: {$taskFiles[$relativePath]['name']}\n";
        }
    }

    if ($badCodeToAdd !== '') {

        $insertPos = strpos($newContent, '<?php');
        if ($insertPos !== false) {
            $insertPos += 6; 

            if (substr($newContent, $insertPos, 2) === "\r\n") {
                $insertPos += 2;
            } elseif (substr($newContent, $insertPos, 1) === "\n") {
                $insertPos += 1;
            }
            $newContent = substr($newContent, 0, $insertPos) . "\n" . $badCodeToAdd . substr($newContent, $insertPos);
        }
    }

    file_put_contents($fullPath, $newContent);
}

echo "\n";
echo "═══════════════════════════════════════════════\n";
if ($dryRun) {
    echo " DRY RUN — NO FILES WERE MODIFIED\n";
}
echo " Files processed:  " . count($phpFiles) . "\n";
echo " Files with changes: " . $totalFilesWithChanges . "\n";
echo " Total comments removed: " . $totalCommentsRemoved . "\n";
$taskFileCount = count(array_intersect_key($taskFiles, array_flip($phpFiles)));
echo " Task files with bad-version comments added: " . count($taskFiles) . " defined, " . $taskFileCount . " found\n";
echo "═══════════════════════════════════════════════\n";
