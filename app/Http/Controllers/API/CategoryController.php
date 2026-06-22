<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Services\CachedProductService;
use Illuminate\Support\Facades\Cache;

class CategoryController extends Controller
{
    public function __construct(protected CachedProductService $cachedProductService)
    {
    }

    public function index()
    {
        $categories = $this->cachedProductService->getCategoryList();

        return $this->success($categories);
    }

    public function show(int $id)
    {
        $category = $this->cachedProductService->getCategoryDetail($id);

        return $this->success($category);
    }

    public function products(int $id)
    {
        $products = $this->cachedProductService->getCategoryProducts($id, request()->get('per_page', 20));

        return $this->paginated($products);
    }
}
