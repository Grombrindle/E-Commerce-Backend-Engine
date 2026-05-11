<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * ProductController — Public product browsing.
 *
 * @GET /api/v1/products        → index()
 * @GET /api/v1/products/{id}   → show()
 */
class ProductController extends Controller
{
    /**
     * List products with search, filters, and pagination.
     * Results cached for 5 minutes.
     */
    public function index(Request $request)
    {
        $request->validate([
            'search'      => 'nullable|string|max:100',
            'category'    => 'nullable|integer|exists:categories,id',
            'min_price'   => 'nullable|numeric|min:0',
            'max_price'   => 'nullable|numeric|min:0',
            'sort'        => 'nullable|in:price_asc,price_desc,newest,name',
            'in_stock'    => 'nullable|boolean',
            'per_page'    => 'nullable|integer|between:1,100',
        ]);

        $cacheKey = 'products:' . md5(serialize($request->all()));

        $products = Cache::remember($cacheKey, 300, function () use ($request) {
            $query = Product::active()
                ->with(['category:id,name,slug', 'inventory:product_id,quantity,reserved_quantity'])
                ->when($request->search, fn($q) => $q->search($request->search))
                ->when($request->category, fn($q) => $q->byCategory($request->category))
                ->when($request->min_price || $request->max_price,
                    fn($q) => $q->priceBetween($request->min_price, $request->max_price))
                ->when($request->boolean('in_stock'), fn($q) => $q->inStock());

            switch ($request->sort) {
                case 'price_asc':  $query->orderBy('price'); break;
                case 'price_desc': $query->orderByDesc('price'); break;
                case 'name':       $query->orderBy('name'); break;
                default:           $query->orderByDesc('created_at');
            }

            return $query->paginate($request->per_page ?? 20);
        });

        return $this->paginated($products);
    }

    /**
     * Get single product detail. Cached for 10 minutes.
     */
    public function show(int $id)
    {
        $product = Cache::remember("product:{$id}", 600, fn() =>
            Product::active()
                ->with(['category:id,name,slug', 'inventory'])
                ->findOrFail($id)
        );

        return $this->success($product);
    }
}
