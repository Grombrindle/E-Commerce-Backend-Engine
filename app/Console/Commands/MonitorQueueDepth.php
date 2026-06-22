<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class MonitorQueueDepth extends Command
{
    protected $signature = 'queue:monitor
                          {--warning=500 : Warning threshold}
                          {--critical=1000 : Critical threshold}';

    protected $description = 'Monitor queue depths and store metrics in cache for health checks and Prometheus scraping';

    public function handle(): void
    {

        $queues = ['default', 'invoices', 'notifications', 'analytics', 'batch-processing', 'heavy'];
        $warning = (int) $this->option('warning');
        $critical = (int) $this->option('critical');

        $results = [];

        foreach ($queues as $queue) {
            try {

                $key = "{" . $queue . "}";
                $size = Redis::llen($key) ?? 0;
                $results[$queue] = $size;

                if ($size === 0) {
                    $altKey = "laravel_database_" . $key;
                    $altSize = Redis::llen($altKey) ?? 0;
                    if ($altSize > 0) {
                        $size = $altSize;
                    }
                }

                Cache::set("queue:monitor:{$queue}", $size, 300);
            } catch (\Throwable $e) {
                $results[$queue] = 0;
                Cache::set("queue:monitor:{$queue}", 0, 300);
            }
        }

        $total = array_sum($results);
        Cache::set('queue:monitor:size', $total, 300);

        foreach ($results as $queue => $size) {
            if ($size >= $critical) {
                Log::critical("Queue '{$queue}' depth CRITICAL: {$size}");
            } elseif ($size >= $warning) {
                Log::warning("Queue '{$queue}' depth WARNING: {$size}");
            }
        }

        $this->table(['Queue', 'Depth'], collect($results)->map(fn($s, $q) => [$q, $s])->toArray());
        $this->info("Total queue depth: {$total}");
    }
}
