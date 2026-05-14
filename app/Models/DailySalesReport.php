<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * DailySalesReport — Stores chunk-level sales aggregation results.
 *
 * Each chunk of orders processed updates its own row (upsert by date).
 * A final aggregation query can sum all chunks for the final report.
 */
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
