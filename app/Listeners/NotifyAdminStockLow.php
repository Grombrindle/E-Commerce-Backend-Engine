<?php

namespace App\Listeners;

use App\Events\StockLow;
use Illuminate\Support\Facades\Log;

class NotifyAdminStockLow
{
    public function handle(StockLow $event): void
    {
        Log::warning("LOW STOCK ALERT: Product '{$event->product->name}' (ID: {$event->product->id}) is running low.");
        // In production: Mail::to(config('app.admin_email'))->send(new LowStockAlert($event->product));
    }
}
