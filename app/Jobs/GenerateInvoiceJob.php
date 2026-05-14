<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\InvoiceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * GenerateInvoiceJob — Generates and stores a PDF invoice for an order.
 *
 * Dispatched asynchronously after order placement.
 * Retries up to 3 times on failure.
 */
class GenerateInvoiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 60;

    public function __construct(public readonly Order $order) {}

    public function handle(InvoiceService $invoiceService): void
    {
        $invoiceService->generate($this->order);

        Log::info("Invoice generated for order #{$this->order->order_number}");
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("Invoice generation failed for order #{$this->order->order_number}: {$exception->getMessage()}");
    }
}
