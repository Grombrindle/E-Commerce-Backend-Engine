<?php

namespace App\Prometheus;

use Spatie\Prometheus\Collectors\Collector;
use Spatie\Prometheus\Facades\Prometheus;
use Illuminate\Support\Facades\Redis;

class DatabaseMetricsCollector implements Collector
{
    public function register(): void
    {

        Prometheus::addGauge('DB queries total')
            ->name('db_queries_total')
            ->labels(['connection', 'type'])
            ->helpText('Total number of database queries')
            ->value(fn() => $this->readFromRedis('metrics:db_queries'));

        Prometheus::addGauge('DB slow queries total')
            ->name('db_slow_queries_total')
            ->labels(['connection', 'table'])
            ->helpText('Total number of slow database queries (>100ms)')
            ->value(fn() => $this->readFromRedis('metrics:db_slow_queries'));

        Prometheus::addGauge('DB connections active')
            ->name('db_connections_active')
            ->labels(['connection'])
            ->helpText('Number of active database connections')
            ->value(fn() => $this->readFromRedis('metrics:db_connections'));

        Prometheus::addGauge('DB transactions total')
            ->name('db_transactions_total')
            ->labels(['connection', 'status'])
            ->helpText('Total number of database transactions')
            ->value(fn() => $this->readFromRedis('metrics:db_transactions'));
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
