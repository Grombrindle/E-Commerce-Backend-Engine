<?php

namespace App\Jobs;

use App\Models\DailySalesReport;
use App\Models\Order;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * ProcessSalesChunkJob — Processes a single chunk of order IDs for daily sales reporting.
 *
 * Each job fetches only its chunk's records, so memory stays bounded to chunk size.
 * Results are upserted atomically — safe for concurrent chunk writers.
 */
class ProcessSalesChunkJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 120;

    /**
     * @param array<int> $orderIds  IDs only — NOT full models (keeps payload small)
     * @param string     $date
     */
    public function __construct(
        private array  $orderIds,
        private string $date
    ) {}

    public function handle(): void
    {
        // Check if the whole batch was cancelled before doing work
        if ($this->batch()?->cancelled()) {
            return;
        }

        // Fetch only this chunk's records — memory stays bounded to chunk size
        $orders = Order::whereIn('id', $this->orderIds)->get();

        $chunkRevenue = $orders->sum('total');
        $chunkCount   = $orders->count();

        // Upsert partial results — atomic, safe for concurrent chunk writers
        DailySalesReport::upsert(
            [
                [
                    'date'          => $this->date,
                    'chunk_revenue' => $chunkRevenue,
                    'chunk_count'   => $chunkCount,
                    'processed_at'  => now(),
                ]
            ],
            ['date'],
            ['chunk_revenue', 'chunk_count', 'processed_at']
        );
    }
}
