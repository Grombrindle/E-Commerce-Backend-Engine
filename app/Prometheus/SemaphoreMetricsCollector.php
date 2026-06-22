<?php

namespace App\Prometheus;

use Spatie\Prometheus\Collectors\Collector;
use Spatie\Prometheus\Facades\Prometheus;
use Illuminate\Support\Facades\Redis;

class SemaphoreMetricsCollector implements Collector
{
    public function register(): void
    {

        Prometheus::addGauge('Semaphore current usage')
            ->name('semaphore_current_usage')
            ->labels(['key'])
            ->helpText('Current semaphore usage count')
            ->value(fn() => $this->readFromRedis('metrics:semaphore_current_usage'));

        Prometheus::addGauge('Semaphore max concurrent')
            ->name('semaphore_max_concurrent')
            ->labels(['key'])
            ->helpText('Maximum concurrent semaphore slots')
            ->value(fn() => $this->readFromRedis('metrics:semaphore_max_concurrent'));

        Prometheus::addGauge('Semaphore utilization ratio')
            ->name('semaphore_utilization_ratio')
            ->labels(['key'])
            ->helpText('Current semaphore utilization as ratio of max (0.0 - 1.0)')
            ->value(fn() => $this->readFromRedis('metrics:semaphore_utilization_ratio'));

        Prometheus::addGauge('Semaphore acquires total')
            ->name('semaphore_acquires_total')
            ->labels(['key', 'result'])
            ->helpText('Total number of semaphore acquire attempts')
            ->value(fn() => $this->readFromRedis('metrics:semaphore_acquires'));
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
