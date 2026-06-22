<?php

// ═══════════════════════════════════════════════════════════════════════
// BEFORE — Task 4: No Chunking (Memory Exhaustion)
// ═══════════════════════════════════════════════════════════════════════
//
// Bad handle() — loads ALL orders into memory at once:
//
//  public function handleBad(): void
//  {
//      // ⚠ get() hydrates ALL rows — 100K orders = ~200MB RAM
//      $orders = Order::whereDate('created_at', $this->date)->get();
//      foreach ($orders as $order) { /* process */ }
//      // ⚠ If OOM, no report is generated at all
//  }
//
// ═══════════════════════════════════════════════════════════════════════
// AFTER (current code): chunk(500) + Bus::batch() + allowFailures()
// ═══════════════════════════════════════════════════════════════════════


namespace App\Jobs;

use App\Models\Order;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Throwable;

class DispatchDailySalesBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 1;
    public int $timeout = 600;

    public function __construct(private string $date) {}

    public function handle(): void
    {
        $jobs      = [];
        $chunkSize = 500;

        Order::whereDate('created_at', $this->date)
             ->select('id')
             ->chunk($chunkSize, function ($orders) use (&$jobs) {
                 $jobs[] = new ProcessSalesChunkJob(
                     $orders->pluck('id')->toArray(),
                     $this->date
                 );
             });

        if (empty($jobs)) {
            Log::info("No orders found for {$this->date}.");
            return;
        }

        Bus::batch($jobs)
           ->name("Daily Sales Report — {$this->date}")
           ->onQueue('batch-processing')
           ->allowFailures()
           ->then(function (Batch $batch) {
               Log::info("Daily sales batch complete. Processed {$batch->totalJobs} chunks.");
           })
           ->catch(function (Batch $batch, Throwable $e) {
               Log::error("Batch processing error: {$e->getMessage()}", [
                   'failed_jobs' => $batch->failedJobs,
                   'total_jobs'  => $batch->totalJobs,
               ]);
           })
           ->finally(function (Batch $batch) {
               Log::info("Batch {$batch->id} finished. Success: {$batch->processedJobs()}/{$batch->totalJobs}");
           })
           ->dispatch();
    }
}
