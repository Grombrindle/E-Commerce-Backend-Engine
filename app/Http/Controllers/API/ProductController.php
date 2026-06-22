<?php

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

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\CachedProductService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ProductController extends Controller
{
    public function __construct(protected CachedProductService $cachedProductService)
    {
    }

    public function index(Request $request)
    {
        $request->validate([
            'search' => 'nullable|string|max:100',
            'category' => 'nullable|integer|exists:categories,id',
            'min_price' => 'nullable|numeric|min:0',
            'max_price' => 'nullable|numeric|min:0',
            'sort' => 'nullable|in:price_asc,price_desc,newest,name',
            'in_stock' => 'nullable|boolean',
            'per_page' => 'nullable|integer|between:1,100',
        ]);

        $products = $this->cachedProductService->getProductList($request);

        return $this->paginated($products);
    }

    public function show(int $id)
    {
        $product = $this->cachedProductService->getProductDetail($id);

        return $this->success($product);
    }
}
