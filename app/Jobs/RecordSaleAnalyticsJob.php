<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\AnalyticsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * RecordSaleAnalyticsJob — Records the sale in analytics/reporting tables.
 *
 * Runs on the 'analytics' queue (low priority).
 * Retries up to 2 times.
 */
class RecordSaleAnalyticsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public readonly Order $order) {}

    public function handle(AnalyticsService $analyticsService): void
    {
        $analyticsService->recordSale($this->order);

        Log::info("Sale analytics recorded for order #{$this->order->order_number}");
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("Analytics recording failed for order #{$this->order->order_number}: {$exception->getMessage()}");
    }
}
