<?php

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
namespace App\Services;

use App\Helpers\CacheHelper;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class CachedProductService
{

    public function getProductList(Request $request): LengthAwarePaginator
    {
        $cacheKey = 'products:list:' . md5(serialize($request->all()));
        $tags = ['products', 'products:list'];
        $start = microtime(true);

        $cached = CacheHelper::get($cacheKey, $tags);
        if ($cached !== null) {
            $this->recordCacheHit('products:list');
            $this->recordCacheOperation('get', 'redis', microtime(true) - $start);
            $this->trackCacheHitRatio();
            return $cached;
        }

        $this->recordCacheMiss('products:list');
        $result = $this->buildProductQuery($request)->paginate($request->per_page ?? 20);

        CacheHelper::put($cacheKey, $result, 300, $tags);
        $this->recordCacheOperation('set', 'redis', microtime(true) - $start);
        $this->trackCacheHitRatio();
        $this->trackCacheSize('products:list');

        return $result;
    }

    private function buildProductQuery(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        $query = Product::active()
            ->with(['category:id,name,slug', 'inventory:product_id,quantity,reserved_quantity'])
            ->when($request->search, fn($q) => $q->search($request->search))
            ->when($request->category, fn($q) => $q->byCategory($request->category))
            ->when(
                $request->min_price || $request->max_price,
                fn($q) => $q->priceBetween($request->min_price, $request->max_price)
            )
            ->when($request->boolean('in_stock'), fn($q) => $q->inStock());

        switch ($request->sort) {
            case 'price_asc': $query->orderBy('price'); break;
            case 'price_desc': $query->orderByDesc('price'); break;
            case 'name': $query->orderBy('name'); break;
            default: $query->orderByDesc('created_at');
        }

        return $query;
    }

    public function getProductDetail(int $id): Product
    {
        $cacheKey = "product:{$id}";
        $tags = ['products', "product:{$id}"];
        $start = microtime(true);

        $cached = CacheHelper::get($cacheKey, $tags);
        if ($cached !== null) {
            $this->recordCacheHit('product:detail');
            $this->recordCacheOperation('get', 'redis', microtime(true) - $start);
            $this->trackCacheHitRatio();
            return $cached;
        }

        $this->recordCacheMiss('product:detail');
        $result = Product::active()
            ->with(['category:id,name,slug', 'inventory'])
            ->findOrFail($id);

        CacheHelper::put($cacheKey, $result, 600, $tags);
        $this->recordCacheOperation('set', 'redis', microtime(true) - $start);
        $this->trackCacheHitRatio();
        $this->trackCacheSize('product:detail');

        return $result;
    }

    public function getProductDetailWithStampedeProtection(int $id): Product
    {
        $cacheKey = "product:{$id}";
        $start = microtime(true);

        $cached = CacheHelper::get($cacheKey, ['products', "product:{$id}"]);
        if ($cached !== null) {
            $this->recordCacheHit('product:detail');
            $this->recordCacheOperation('get', 'redis', microtime(true) - $start);
            $this->trackCacheHitRatio();
            return $cached;
        }

        $this->recordCacheMiss('product:detail');
        $lock = Cache::lock("product:{$id}:lock", 10);

        if ($lock->get()) {
            try {
                $doubleCheck = CacheHelper::get($cacheKey, ['products', "product:{$id}"]);
                if ($doubleCheck !== null) {
                    $this->recordCacheHit('product:detail');
                    $this->recordCacheOperation('get', 'redis', microtime(true) - $start);
                    $this->trackCacheHitRatio();
                    return $doubleCheck;
                }

                $product = Product::active()
                    ->with(['category:id,name,slug', 'inventory'])
                    ->findOrFail($id);

                CacheHelper::put($cacheKey, $product, 600, ['products', "product:{$id}"]);
                $this->recordCacheOperation('set', 'redis', microtime(true) - $start);
                $this->trackCacheHitRatio();
                $this->trackCacheSize('product:detail');
                return $product;
            } finally {
                $lock->release();
            }
        }

        $product = Product::active()
            ->with(['category:id,name,slug', 'inventory'])
            ->findOrFail($id);
        $this->recordCacheOperation('get', 'redis', microtime(true) - $start);
        return $product;
    }

    public function getCategoryList(): \Illuminate\Database\Eloquent\Collection
    {
        $cacheKey = 'categories:all';
        $start = microtime(true);

        $cached = CacheHelper::get($cacheKey);
        if ($cached !== null) {
            $this->recordCacheHit('categories:all');
            $this->recordCacheOperation('get', 'redis', microtime(true) - $start);
            $this->trackCacheHitRatio();
            return $cached;
        }

        $this->recordCacheMiss('categories:all');
        $result = Category::where('is_active', true)
            ->withCount(['activeProducts'])
            ->orderBy('name')
            ->get();

        CacheHelper::put($cacheKey, $result, 600);
        $this->recordCacheOperation('set', 'redis', microtime(true) - $start);
        $this->trackCacheHitRatio();
        $this->trackCacheSize('categories');

        return $result;
    }

    public function getCategoryDetail(int $id): Category
    {
        $cacheKey = "category:{$id}";
        $start = microtime(true);

        $cached = CacheHelper::get($cacheKey);
        if ($cached !== null) {
            $this->recordCacheHit('category:detail');
            $this->recordCacheOperation('get', 'redis', microtime(true) - $start);
            $this->trackCacheHitRatio();
            return $cached;
        }

        $this->recordCacheMiss('category:detail');
        $result = Category::where('is_active', true)
            ->withCount(['activeProducts'])
            ->findOrFail($id);

        CacheHelper::put($cacheKey, $result, 600);
        $this->recordCacheOperation('set', 'redis', microtime(true) - $start);
        $this->trackCacheHitRatio();
        $this->trackCacheSize('category:detail');

        return $result;
    }

    public function getCategoryProducts(int $categoryId, int $perPage = 20): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $cacheKey = "category:{$categoryId}:products:page:" . request()->get('page', 1) . ":per_page:{$perPage}";
        $start = microtime(true);

        $cached = CacheHelper::get($cacheKey);
        if ($cached !== null) {
            $this->recordCacheHit('category:products');
            $this->recordCacheOperation('get', 'redis', microtime(true) - $start);
            return $cached;
        }

        $this->recordCacheMiss('category:products');
        $category = \App\Models\Category::where('is_active', true)->findOrFail($categoryId);
        $products = $category->activeProducts()
            ->with('inventory:product_id,quantity,reserved_quantity')
            ->orderByDesc('created_at')
            ->paginate($perPage);

        CacheHelper::put($cacheKey, $products, 300);
        $this->recordCacheOperation('set', 'redis', microtime(true) - $start);
        $this->trackCacheHitRatio();

        return $products;
    }

    public function flushProductCaches(): void
    {
        CacheHelper::flush(['products']);
        Log::debug('Product caches flushed.');
    }

    public function flushCategoryCaches(): void
    {
        Cache::forget('categories:all');
        Log::debug('Category caches flushed.');
    }

    private function recordCacheHit(string $pattern): void
    {
        try { Redis::hincrby('metrics:cache_hits', "{$pattern}|", 1); } catch (\Throwable $e) {}
    }

    private function recordCacheMiss(string $pattern): void
    {
        try { Redis::hincrby('metrics:cache_misses', "{$pattern}|", 1); } catch (\Throwable $e) {}
    }

    private function recordCacheOperation(string $operation, string $driver, float $duration): void
    {
        try { Redis::hset('metrics:cache_duration', "{$operation}|{$driver}", $duration); } catch (\Throwable $e) {}
    }

    private function trackCacheHitRatio(): void
    {
        try {
            $hits = array_sum(Redis::hvals('metrics:cache_hits') ?: [0]);
            $misses = array_sum(Redis::hvals('metrics:cache_misses') ?: [0]);
            $total = $hits + $misses;
            Redis::hset('metrics:cache_hit_ratio', 'overall', $total > 0 ? $hits / $total : 0);
        } catch (\Throwable $e) {}
    }

    private function trackCacheSize(string $pattern): void
    {
        try {
            $keys = Redis::keys("*{$pattern}*");
            Redis::hset('metrics:cache_size', $pattern, count($keys));
        } catch (\Throwable $e) {}
    }
}
