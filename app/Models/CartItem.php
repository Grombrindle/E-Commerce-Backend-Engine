<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartItem extends Model
{
    protected $fillable = ['cart_id', 'product_id', 'quantity', 'price'];

    protected $casts = [
        'quantity' => 'integer',
        'price'    => 'decimal:2',
    ];

    // ── Relationships ──────────────────────────────────────────────────
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    // ── Helpers ────────────────────────────────────────────────────────
    public function getSubtotalAttribute(): float
    {
        return $this->quantity * $this->price;
    }
}
