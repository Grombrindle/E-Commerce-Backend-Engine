<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailySalesReport extends Model
{
    protected $fillable = [
        'date', 'chunk_revenue', 'chunk_count', 'processed_at',
    ];

    protected $casts = [
        'date'          => 'date',
        'chunk_revenue' => 'decimal:2',
        'chunk_count'   => 'integer',
        'processed_at'  => 'datetime',
    ];

    public $timestamps = true;
}
