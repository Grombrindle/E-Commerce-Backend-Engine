<?php

/* ============================================================
 * ╔══════════════════════════════════════════════════════════════╗
 * ║  BEFORE — Task 4: Memory-Killing Batch (The Problem)        ║
 * ╚══════════════════════════════════════════════════════════════╝
 *
 * The original code loaded ALL orders into memory at once.
 * 100,000 orders × ~2KB each = ~200MB RAM spike.
 * PHP memory_limit exceeded → Fatal Error → no report.
 *
 *          Bad code (get() — loads everything):
 *
 *          public function handle(): void
 *          {
 *              // ⚠ Loads ALL records into memory at once
 *              // 100,000 records × 2KB = 200MB RAM
 *              $orders = Order::whereDate('created_at', $this->date)->get();
 *
 *              $totalRevenue = 0;
 *              $totalOrders  = 0;
 *
 *              // ⚠ All models sit in RAM during the loop
 *              foreach ($orders as $order) {
 *                  $totalRevenue += $order->total;
 *                  $totalOrders++;
 *              }
 *
 *              // ⚠ Single-threaded, memory-inefficient
 *              echo "Processed {$totalOrders} orders. Revenue: {$totalRevenue}";
 *          }
 *
 * What went wrong:
 *  - get() hydrates ALL rows into PHP objects → OOM
 *  - No chunking → memory grows linearly with data size
 *  - Single-threaded → slow for large datasets
 *  - No fault tolerance → one bad record crashes everything
 *
 * ╔══════════════════════════════════════════════════════════════╗
 * ║  AFTER — Task 4: Chunked Batch Processing (Fix)             ║
 * ╚══════════════════════════════════════════════════════════════╝
 *
 *          ✅ chunk(500) fetches 500 rows at a time
 *          ✅ Memory = O(chunk_size), not O(total_records)
 *          ✅ Bus::batch() runs chunks in PARALLEL
 *          ✅ allowFailures() isolates bad chunks
 *          ✅ select('id') minimizes chunk payload
 *
 * To test the bad code:
 *   1. Replace the chunk+batch logic with Order::get() loop
 *   2. Create 100,000 test orders
 *   3. Run — PHP runs out of memory (if memory_limit is low)
 *   4. Restore — chunks process safely regardless of data size
 * ============================================================ */

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

/**
 * DispatchDailySalesBatchJob — Orchestrates the daily sales batch processing.
 *
 * Reads order IDs in memory-efficient chunks and dispatches them
 * in parallel via Bus::batch().
 *
 * Memory safety (Task 4):
 *  - chunk() fetches 500 at a time from DB
 *  - Select only 'id' to minimize each chunk fetch
 *  - Bus::batch() for parallel execution
 */
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
