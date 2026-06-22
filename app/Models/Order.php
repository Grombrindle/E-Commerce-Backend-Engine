<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    use HasFactory;

    const STATUS_PENDING    = 'pending';
    const STATUS_CONFIRMED  = 'confirmed';
    const STATUS_PROCESSING = 'processing';
    const STATUS_SHIPPED    = 'shipped';
    const STATUS_DELIVERED  = 'delivered';
    const STATUS_CANCELLED  = 'cancelled';
    const STATUS_REFUNDED   = 'refunded';

    protected $fillable = [
        'user_id', 'order_number', 'status', 'subtotal', 'tax',
        'shipping_fee', 'discount', 'total', 'shipping_address',
        'billing_address', 'notes', 'cancelled_at', 'cancel_reason',
    ];

    protected $casts = [
        'subtotal'         => 'decimal:2',
        'tax'              => 'decimal:2',
        'shipping_fee'     => 'decimal:2',
        'discount'         => 'decimal:2',
        'total'            => 'decimal:2',
        'shipping_address' => 'array',
        'billing_address'  => 'array',
        'cancelled_at'     => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(fn($order) => $order->order_number = self::generateOrderNumber());
    }

    public static function generateOrderNumber(): string
    {
        return 'ORD-' . strtoupper(substr(md5(uniqid()), 0, 8)) . '-' . date('Ymd');
    }

    public function scopePending($q)    { return $q->where('status', self::STATUS_PENDING); }
    public function scopeCancellable($q){ return $q->whereIn('status', [self::STATUS_PENDING, self::STATUS_CONFIRMED]); }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    public function isCancellable(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_CONFIRMED]);
    }

    public function isPaid(): bool
    {
        return $this->payment && $this->payment->status === Payment::STATUS_PAID;
    }
}
