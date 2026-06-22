<?php

namespace App\Prometheus;

use Spatie\Prometheus\Collectors\Collector;
use Spatie\Prometheus\Facades\Prometheus;
use Illuminate\Support\Facades\Redis;

class CacheMetricsCollector implements Collector
{
    public function register(): void
    {

        Prometheus::addGauge('Cache hits total')
            ->name('cache_hits_total')
            ->labels(['key', 'tags'])
            ->helpText('Total number of cache hits')
            ->value(fn() => $this->readFromRedis('metrics:cache_hits'));

        Prometheus::addGauge('Cache misses total')
            ->name('cache_misses_total')
            ->labels(['key', 'tags'])
            ->helpText('Total number of cache misses')
            ->value(fn() => $this->readFromRedis('metrics:cache_misses'));

        Prometheus::addGauge('Cache hit ratio')
            ->name('cache_hit_ratio')
            ->labels(['key_pattern'])
            ->helpText('Current cache hit ratio')
            ->value(fn() => $this->readFromRedis('metrics:cache_hit_ratio'));

        Prometheus::addGauge('Cache operation duration seconds')
            ->name('cache_operation_duration_seconds')
            ->labels(['operation', 'driver'])
            ->helpText('Cache operation duration in seconds (average)')
            ->value(fn() => $this->readFromRedis('metrics:cache_duration'));

        Prometheus::addGauge('Cache size')
            ->name('cache_size')
            ->labels(['key_pattern'])
            ->helpText('Estimated number of cache entries')
            ->value(fn() => $this->readFromRedis('metrics:cache_size'));
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
