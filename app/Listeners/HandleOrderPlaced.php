<?php

namespace App\Listeners;

use App\Events\OrderPlaced;
use Illuminate\Support\Facades\Log;

class HandleOrderPlaced
{
    public function handle(OrderPlaced $event): void
    {

        Log::info("Order placed event fired for #{$event->order->order_number}");
    }
}
