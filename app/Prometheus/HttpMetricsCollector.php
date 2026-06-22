<?php

namespace App\Prometheus;

use Spatie\Prometheus\Collectors\Collector;
use Spatie\Prometheus\Facades\Prometheus;
use Illuminate\Support\Facades\Redis;

class HttpMetricsCollector implements Collector
{
    public function register(): void
    {

        Prometheus::addGauge('HTTP requests total')
            ->name('http_requests_total')
            ->labels(['method', 'route', 'status_code'])
            ->helpText('Total number of HTTP requests')
            ->value(fn() => $this->readFromRedis('metrics:http_requests'));

        Prometheus::addGauge('HTTP request duration seconds')
            ->name('http_request_duration_seconds')
            ->labels(['method', 'route'])
            ->helpText('HTTP request duration in seconds')
            ->value(fn() => $this->readFromRedis('metrics:http_duration'));

        Prometheus::addGauge('HTTP requests active')
            ->name('http_requests_active')
            ->helpText('Number of active HTTP requests')
            ->value(fn() => (float) (Redis::get('metrics:http_active') ?? 0));

        Prometheus::addGauge('HTTP errors total')
            ->name('http_errors_total')
            ->labels(['method', 'route', 'status_code'])
            ->helpText('Total number of HTTP errors')
            ->value(fn() => $this->readFromRedis('metrics:http_errors'));
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
