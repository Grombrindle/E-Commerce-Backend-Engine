<?php

// ═══════════════════════════════════════════════════════════════════════
// BEFORE — Task 1 & Cart: No Reservation Check (isAvailable checks quantity only)
// ═══════════════════════════════════════════════════════════════════════
//
// Bad isAvailable() — ignores reserved_quantity, allowing oversell:
//
//  public function isAvailableBad(int $qty = 1): bool
//  {
//      if (!$this->inventory) return false;
//      // ⚠ Only checks quantity, ignores reserved_quantity
//      // Two users could each reserve 5 of 10 total stock
//      return $this->inventory->quantity >= $qty;
//  }
//
// ═══════════════════════════════════════════════════════════════════════
// AFTER (current code): isAvailable() checks quantity - reserved_quantity
// ═══════════════════════════════════════════════════════════════════════

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'category_id',
        'name',
        'slug',
        'description',
        'price',
        'compare_price',
        'sku',
        'image_url',
        'images',
        'is_active',
        'weight',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'compare_price' => 'decimal:2',
        'is_active' => 'boolean',
        'images' => 'array',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
    public function scopeInStock($query)
    {

        return $query->whereHas('inventory', function ($q) {
            $q->whereRaw('quantity - reserved_quantity > 0');
        });
    }
    public function scopeByCategory($q, $catId)
    {
        return $q->where('category_id', $catId);
    }
    public function scopeSearch($q, $term)
    {
        return $q->where(fn($q) => $q->where('name', 'like', "%{$term}%")
            ->orWhere('description', 'like', "%{$term}%")
            ->orWhere('sku', 'like', "%{$term}%"));
    }
    public function scopePriceBetween($q, $min, $max)
    {
        return $q->when($min, fn($q) => $q->where('price', '>=', $min))
            ->when($max, fn($q) => $q->where('price', '<=', $max));
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function inventory(): HasOne
    {
        return $this->hasOne(Inventory::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function isAvailable(int $qty = 1): bool
    {
        if (!$this->inventory) {
            return false;
        }
        $available = $this->inventory->quantity - $this->inventory->reserved_quantity;
        return $available >= $qty;
    }

    public function getDiscountPercentAttribute(): int
    {
        if (!$this->compare_price || $this->compare_price <= $this->price)
            return 0;
        return (int) round(($this->compare_price - $this->price) / $this->compare_price * 100);
    }
}
