<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    const STATUS_PENDING  = 'pending';
    const STATUS_PAID     = 'paid';
    const STATUS_FAILED   = 'failed';
    const STATUS_REFUNDED = 'refunded';

    const METHOD_CARD        = 'card';
    const METHOD_BANK        = 'bank_transfer';
    const METHOD_COD         = 'cash_on_delivery';
    const METHOD_WALLET      = 'wallet';

    protected $fillable = [
        'order_id', 'user_id', 'payment_number', 'method',
        'status', 'amount', 'currency', 'gateway',
        'gateway_transaction_id', 'gateway_response', 'paid_at', 'failed_reason',
    ];

    protected $casts = [
        'amount'            => 'decimal:2',
        'gateway_response'  => 'array',
        'paid_at'           => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(fn($p) => $p->payment_number = 'PAY-' . strtoupper(substr(md5(uniqid()), 0, 10)));
    }

    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function user(): BelongsTo  { return $this->belongsTo(User::class); }

    public function isPaid(): bool    { return $this->status === self::STATUS_PAID; }
    public function isFailed(): bool  { return $this->status === self::STATUS_FAILED; }
}
