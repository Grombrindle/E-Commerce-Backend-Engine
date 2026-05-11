<?php

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
        'category_id', 'name', 'slug', 'description', 'price',
        'compare_price', 'sku', 'image_url', 'images', 'is_active', 'weight',
    ];

    protected $casts = [
        'price'         => 'decimal:2',
        'compare_price' => 'decimal:2',
        'is_active'     => 'boolean',
        'images'        => 'array',
    ];

    // ── Scopes ─────────────────────────────────────────────────────────
    public function scopeActive($query)           { return $query->where('is_active', true); }
    public function scopeInStock($query)          { return $query->whereHas('inventory', fn($q) => $q->where('quantity', '>', 0)); }
    public function scopeByCategory($q, $catId)  { return $q->where('category_id', $catId); }
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

    // ── Relationships ──────────────────────────────────────────────────
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

    // ── Helpers ────────────────────────────────────────────────────────
    public function isAvailable(int $qty = 1): bool
    {
        return $this->inventory && $this->inventory->quantity >= $qty;
    }

    public function getDiscountPercentAttribute(): int
    {
        if (!$this->compare_price || $this->compare_price <= $this->price) return 0;
        return (int) round(($this->compare_price - $this->price) / $this->compare_price * 100);
    }
}
