<?php

return [

    'default' => env('LOG_CHANNEL', 'stack'),

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => env('LOG_DEPRECATIONS_TRACE', false),
    ],

    'channels' => [
        'stack' => [
            'driver' => 'stack',
            'channels' => ['single', 'checkout'],
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'warning'),
            'replace_placeholders' => true,
        ],

        'checkout' => [
            'driver' => 'daily',
            'path' => storage_path('logs/checkout/checkout.log'),
            'level' => env('LOG_LEVEL_STEPS', 'debug'),
            'days' => 14,
            'replace_placeholders' => true,
        ],
    ],
];
