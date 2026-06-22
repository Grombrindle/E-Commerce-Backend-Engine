<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\Log;

class AnalyticsService
{
    public function recordSale(Order $order): void
    {
        Log::info("Analytics: Sale recorded for order #{$order->order_number}, total: {$order->total}");
    }
}
