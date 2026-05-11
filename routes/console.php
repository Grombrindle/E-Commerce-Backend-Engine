<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule: clean expired carts every night
Schedule::command('cart:clean-expired')->daily();

// Schedule: generate daily sales report
Schedule::command('orders:daily-report')->dailyAt('06:00');
