<?php

namespace App\Listeners;

use App\Events\OrderPlaced;
use Illuminate\Support\Facades\Log;

class HandleOrderPlaced
{
    public function handle(OrderPlaced $event): void
    {
        // Example: update user's total orders count in cache
        Log::info("Order placed event fired for #{$event->order->order_number}");
    }
}
