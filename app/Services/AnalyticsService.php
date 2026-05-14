<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\Log;

/**
 * AnalyticsService — Records sales data for reporting.
 *
 * In production, this would write to a reporting database,
 * increment Redis counters, or call an analytics API.
 */
class AnalyticsService
{
    public function recordSale(Order $order): void
    {
        Log::info("Analytics: Sale recorded for order #{$order->order_number}, total: {$order->total}");
    }
}
