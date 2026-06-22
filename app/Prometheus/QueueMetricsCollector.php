<?php

namespace App\Prometheus;

use Spatie\Prometheus\Collectors\Collector;
use Spatie\Prometheus\Facades\Prometheus;
use Illuminate\Support\Facades\Redis;

class QueueMetricsCollector implements Collector
{
    public function register(): void
    {

        Prometheus::addGauge('Queue depth')
            ->name('queue_depth')
            ->labels(['queue'])
            ->helpText('Current queue depth per queue name')
            ->value(fn() => $this->getQueueDepths());

        Prometheus::addGauge('Queue jobs processed total')
            ->name('queue_jobs_processed_total')
            ->labels(['queue', 'status'])
            ->helpText('Total number of jobs processed')
            ->value(fn() => $this->readFromRedis('metrics:queue_jobs_processed'));

        Prometheus::addGauge('Queue job duration seconds')
            ->name('queue_job_duration_seconds')
            ->labels(['queue', 'job_class'])
            ->helpText('Job processing duration in seconds (average)')
            ->value(fn() => $this->readFromRedis('metrics:queue_job_duration'));
    }

    private function getQueueDepths(): array
    {
        $queues = ['default', 'invoices', 'notifications', 'analytics', 'batch-processing', 'heavy'];
        $results = [];

        foreach ($queues as $queue) {
            try {
                $key = "queues:{$queue}";
                $size = Redis::llen($key) ?? 0;
                $results[] = [(float) $size, [$queue]];
            } catch (\Throwable $e) {
                $results[] = [0.0, [$queue]];
            }
        }

        return $results;
    }

    private function readFromRedis(string $key): array
    {
        try {
            $data = Redis::hgetall($key);
            if (! $data) {
                return [];
            }

            return collect($data)->map(function ($value, $labels) {
                $labelParts = explode('|', $labels);
                return [(float) $value, $labelParts];
            })->values()->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }
}
