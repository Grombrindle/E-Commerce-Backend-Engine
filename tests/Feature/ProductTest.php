<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Cache::flush();
    }

    private function createProduct(array $override = []): Product
    {
        $cat  = Category::factory()->create(['is_active' => true]);
        $prod = Product::factory()->create(array_merge([
            'category_id' => $cat->id,
            'is_active'   => true,
        ], $override));

        Inventory::create([
            'product_id' => $prod->id,
            'quantity'   => 100,
            'reserved_quantity' => 0,
            'low_stock_threshold' => 10,
        ]);

        return $prod;
    }

    public function test_can_list_products(): void
    {
        $this->createProduct();
        $this->createProduct();

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonStructure([
                'success', 'data', 'pagination' => ['total', 'per_page', 'current_page'],
            ]);
    }

    public function test_can_filter_products_by_search(): void
    {
        $this->createProduct(['name' => 'Amazing Widget']);
        $this->createProduct(['name' => 'Other Product']);

        $this->getJson('/api/v1/products?search=Amazing')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Amazing Widget']);
    }

    public function test_can_get_single_product(): void
    {
        $product = $this->createProduct();

        $this->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonFragment(['id' => $product->id]);
    }

    public function test_returns_404_for_nonexistent_product(): void
    {
        $this->getJson('/api/v1/products/99999')
            ->assertNotFound();
    }

    public function test_inactive_products_not_shown(): void
    {
        $active   = $this->createProduct(['is_active' => true]);
        $inactive = $this->createProduct(['is_active' => false]);

        $response = $this->getJson('/api/v1/products')->assertOk();
        $ids = collect($response->json('data'))->pluck('id');

        $this->assertTrue($ids->contains($active->id));
        $this->assertFalse($ids->contains($inactive->id));
    }
}
