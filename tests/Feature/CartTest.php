<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $cat = Category::factory()->create(['is_active' => true]);
        $this->product = Product::factory()->create(['category_id' => $cat->id, 'is_active' => true, 'price' => 99.99]);
        Inventory::create([
            'product_id' => $this->product->id,
            'quantity'   => 50,
            'reserved_quantity' => 0,
            'low_stock_threshold' => 10,
        ]);
    }

    public function test_can_add_item_to_cart(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/cart/items', [
                'product_id' => $this->product->id,
                'quantity'   => 2,
            ])
            ->assertCreated()
            ->assertJsonFragment(['product_id' => $this->product->id]);
    }

    public function test_cannot_add_out_of_stock_item(): void
    {
        $this->product->inventory->update(['quantity' => 0]);

        $this->actingAs($this->user)
            ->postJson('/api/v1/cart/items', [
                'product_id' => $this->product->id,
                'quantity'   => 1,
            ])
            ->assertStatus(422);
    }

    public function test_can_view_cart(): void
    {
        $this->actingAs($this->user)
            ->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonStructure(['success', 'data']);
    }

    public function test_can_remove_item_from_cart(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/cart/items', ['product_id' => $this->product->id, 'quantity' => 1]);

        $cart = $this->user->cart()->with('items')->first();
        $item = $cart->items->first();

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/cart/items/{$item->id}")
            ->assertOk();

        $this->assertDatabaseMissing('cart_items', ['id' => $item->id]);
    }

    public function test_requires_authentication_to_access_cart(): void
    {
        $this->getJson('/api/v1/cart')->assertUnauthorized();
    }
}
