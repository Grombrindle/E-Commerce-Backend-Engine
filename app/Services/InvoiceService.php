<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\Log;

/**
 * InvoiceService — Generates and stores PDF invoices.
 *
 * In production, this would generate a PDF file using a library like
 * DomPDF or Laravel Snappy, store it to S3, and update the order record.
 */
class InvoiceService
{
    public function generate(Order $order): void
    {
        Log::info("Invoice: PDF generated for order #{$order->order_number}");
    }
}
