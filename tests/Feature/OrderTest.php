<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->user = User::factory()->create();
        $cat = Category::factory()->create(['is_active' => true]);
        $this->product = Product::factory()->create(['category_id' => $cat->id, 'is_active' => true, 'price' => 50.00]);
        Inventory::create(['product_id' => $this->product->id, 'quantity' => 100, 'reserved_quantity' => 0, 'low_stock_threshold' => 10]);
    }

    private function addToCart(int $qty = 2): void
    {
        $cart = Cart::create(['user_id' => $this->user->id, 'expires_at' => now()->addDays(1)]);
        CartItem::create(['cart_id' => $cart->id, 'product_id' => $this->product->id, 'quantity' => $qty, 'price' => $this->product->price]);

        // Reserve stock to mirror real CartService behavior (otherwise reserved_quantity goes negative on order)
        $this->product->inventory->increment('reserved_quantity', $qty);
    }

    private function shippingAddress(): array
    {
        return [
            'name' => 'John Doe', 'street' => '123 Test St',
            'city' => 'Test City', 'country' => 'US', 'zip' => '10001',
        ];
    }

    public function test_can_place_order(): void
    {
        $this->addToCart(2);

        $this->actingAs($this->user)
            ->postJson('/api/v1/orders', ['shipping_address' => $this->shippingAddress()])
            ->assertCreated()
            ->assertJsonStructure(['data' => ['id', 'order_number', 'status', 'total']]);

        $this->assertDatabaseHas('orders', ['user_id' => $this->user->id]);
        // Stock should be decremented
        $this->assertEquals(98, $this->product->inventory->fresh()->quantity);
    }

    public function test_stock_decrements_on_order(): void
    {
        $this->addToCart(3);
        $this->actingAs($this->user)
            ->postJson('/api/v1/orders', ['shipping_address' => $this->shippingAddress()]);

        $this->assertEquals(97, $this->product->inventory->fresh()->quantity);
    }

    public function test_cannot_order_with_empty_cart(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/orders', ['shipping_address' => $this->shippingAddress()])
            ->assertStatus(422);
    }

    public function test_can_cancel_pending_order(): void
    {
        $this->addToCart(2);
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/orders', ['shipping_address' => $this->shippingAddress()])
            ->assertCreated();

        $orderId = $response->json('data.id');

        $this->actingAs($this->user)
            ->postJson("/api/v1/orders/{$orderId}/cancel", ['reason' => 'Changed my mind'])
            ->assertOk();

        $this->assertEquals('cancelled', Order::find($orderId)->status);
        // Stock restored
        $this->assertEquals(100, $this->product->inventory->fresh()->quantity);
    }

    public function test_can_list_user_orders(): void
    {
        $this->addToCart();
        $this->actingAs($this->user)->postJson('/api/v1/orders', ['shipping_address' => $this->shippingAddress()]);

        $this->actingAs($this->user)
            ->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonStructure(['data', 'pagination']);
    }
}
