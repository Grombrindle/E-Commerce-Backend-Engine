<?php

return [
    'enabled' => true,

    'urls' => [
        'default' => 'api/v1/metrics',
    ],

    'allowed_ips' => [

    ],

    'default_namespace' => 'ecommerce',

    'middleware' => [
        Spatie\Prometheus\Http\Middleware\AllowIps::class,
    ],

    'actions' => [
        'render_collectors' => Spatie\Prometheus\Actions\RenderCollectorsAction::class,
    ],

    'wipe_storage_after_rendering' => false,

    'cache' => env('PROMETHEUS_CACHE_STORE', null),

    'collectors' => [
        \App\Prometheus\HttpMetricsCollector::class,
        \App\Prometheus\QueueMetricsCollector::class,
        \App\Prometheus\CacheMetricsCollector::class,
        \App\Prometheus\DatabaseMetricsCollector::class,
        \App\Prometheus\SemaphoreMetricsCollector::class,
    ],
];
