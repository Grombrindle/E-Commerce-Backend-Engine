<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Inventory extends Model
{
    protected $fillable = ['product_id', 'quantity', 'reserved_quantity', 'low_stock_threshold'];

    protected $casts = [
        'quantity'          => 'integer',
        'reserved_quantity' => 'integer',
        'low_stock_threshold'=> 'integer',
    ];

    // ── Relationships ──────────────────────────────────────────────────
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    // ── Helpers ────────────────────────────────────────────────────────
    public function getAvailableQuantityAttribute(): int
    {
        return max(0, $this->quantity - $this->reserved_quantity);
    }

    public function isLowStock(): bool
    {
        return $this->available_quantity <= $this->low_stock_threshold;
    }

    public function isOutOfStock(): bool
    {
        return $this->available_quantity <= 0;
    }
}
