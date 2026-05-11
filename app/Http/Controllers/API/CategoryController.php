<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Support\Facades\Cache;

/**
 * CategoryController
 *
 * @GET /api/v1/categories              → index()
 * @GET /api/v1/categories/{id}         → show()
 * @GET /api/v1/categories/{id}/products → products()
 */
class CategoryController extends Controller
{
    public function index()
    {
        $categories = Cache::remember('categories:all', 600, fn() =>
            Category::where('is_active', true)
                ->withCount(['activeProducts'])
                ->orderBy('name')
                ->get()
        );

        return $this->success($categories);
    }

    public function show(int $id)
    {
        $category = Cache::remember("category:{$id}", 600, fn() =>
            Category::where('is_active', true)
                ->withCount(['activeProducts'])
                ->findOrFail($id)
        );

        return $this->success($category);
    }

    public function products(int $id)
    {
        $category = Category::where('is_active', true)->findOrFail($id);

        $products = $category->activeProducts()
            ->with('inventory:product_id,quantity,reserved_quantity')
            ->orderByDesc('created_at')
            ->paginate(20);

        return $this->paginated($products);
    }
}
