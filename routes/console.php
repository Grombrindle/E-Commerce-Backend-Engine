<?php

use App\Jobs\DispatchDailySalesBatchJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('cart:clean-expired')->daily();

Schedule::command('orders:daily-report')->dailyAt('06:00');

Schedule::command('queue:monitor --warning=500 --critical=1000')
    ->everyMinute()
    ->withoutOverlapping()
    ->name('queue-monitor')
    ->runInBackground();

Schedule::job(new DispatchDailySalesBatchJob(now()->subDay()->toDateString()))
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->name('batch-daily-sales');

Schedule::job(new DispatchDailySalesBatchJob(now()->toDateString()))
    ->dailyAt('14:00')
    ->withoutOverlapping()
    ->name('batch-daily-sales-today');
