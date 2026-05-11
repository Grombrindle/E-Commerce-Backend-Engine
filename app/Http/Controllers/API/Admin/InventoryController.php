<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateInventoryRequest;
use App\Models\Inventory;
use App\Services\InventoryService;

/**
 * Admin InventoryController
 *
 * @GET /api/v1/admin/inventory              → index()
 * @GET /api/v1/admin/inventory/{productId}  → show()
 * @PUT /api/v1/admin/inventory/{productId}  → update()
 * @GET /api/v1/admin/inventory/low-stock    → lowStock()
 */
class InventoryController extends Controller
{
    public function __construct(protected InventoryService $inventoryService) {}

    public function index()
    {
        $inventory = Inventory::with('product:id,name,sku,category_id')
            ->orderBy('quantity')
            ->paginate(50);
        return $this->paginated($inventory);
    }

    public function show(int $productId)
    {
        $inv = Inventory::where('product_id', $productId)
            ->with('product:id,name,sku')
            ->firstOrFail();
        return $this->success($inv);
    }

    public function update(UpdateInventoryRequest $request, int $productId)
    {
        $inv = $this->inventoryService->setStock(
            $productId,
            $request->quantity,
            $request->low_stock_threshold ?? 10
        );
        return $this->success($inv, 'Inventory updated.');
    }

    public function lowStock()
    {
        $items = $this->inventoryService->getLowStockProducts();
        return $this->success($items);
    }
}
