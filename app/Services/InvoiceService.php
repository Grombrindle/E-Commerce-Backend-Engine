<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\Log;

class InvoiceService
{
    public function generate(Order $order): void
    {
        Log::info("Invoice: PDF generated for order #{$order->order_number}");
    }
}
