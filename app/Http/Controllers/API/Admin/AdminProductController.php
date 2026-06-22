<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreProductRequest;
use App\Models\Inventory;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class AdminProductController extends Controller
{
    public function index(Request $request)
    {
        $products = Product::withTrashed()
            ->with(['category:id,name', 'inventory'])
            ->orderByDesc('created_at')
            ->paginate($request->per_page ?? 20);

        return $this->paginated($products);
    }

    public function store(StoreProductRequest $request)
    {
        $data = $request->validated();
        $data['slug'] = Str::slug($data['name']) . '-' . substr(uniqid(), -4);

        $product = Product::create($data);

        Inventory::create([
            'product_id'          => $product->id,
            'quantity'            => $request->initial_stock ?? 0,
            'low_stock_threshold' => $request->low_stock_threshold ?? 10,
        ]);

        Cache::forget('products:*');

        return $this->created($product->load('inventory'), 'Product created.');
    }

    public function show(int $id)
    {
        return $this->success(
            Product::withTrashed()->with(['category', 'inventory'])->findOrFail($id)
        );
    }

    public function update(StoreProductRequest $request, int $id)
    {
        $product = Product::findOrFail($id);
        $product->update($request->validated());

        Cache::forget("product:{$id}");

        return $this->success($product->fresh(['category', 'inventory']), 'Product updated.');
    }

    public function destroy(int $id)
    {
        Product::findOrFail($id)->delete();
        Cache::forget("product:{$id}");
        return $this->success(null, 'Product deleted.');
    }
}
