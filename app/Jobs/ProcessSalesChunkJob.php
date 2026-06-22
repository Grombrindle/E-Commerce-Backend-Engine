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

class ProcessSalesChunkJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 120;

    public function __construct(
        private array  $orderIds,
        private string $date
    ) {}

    public function handle(): void
    {

        if ($this->batch()?->cancelled()) {
            return;
        }

        $orders = Order::whereIn('id', $this->orderIds)->get();

        $chunkRevenue = $orders->sum('total');
        $chunkCount   = $orders->count();

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
