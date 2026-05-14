<?php

use App\Jobs\DispatchDailySalesBatchJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule: clean expired carts every night
Schedule::command('cart:clean-expired')->daily();

// Schedule: generate daily sales report (legacy command)
Schedule::command('orders:daily-report')->dailyAt('06:00');

// Task 4: Batch process daily sales via chunked async jobs
Schedule::job(new DispatchDailySalesBatchJob(now()->subDay()->toDateString()))
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->name('batch-daily-sales');

// Task 4: Also run for today's data (for current-day processing)
Schedule::job(new DispatchDailySalesBatchJob(now()->toDateString()))
    ->dailyAt('14:00')
    ->withoutOverlapping()
    ->name('batch-daily-sales-today');
