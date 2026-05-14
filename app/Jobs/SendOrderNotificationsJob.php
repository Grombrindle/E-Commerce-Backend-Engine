<?php

namespace App\Jobs;

use App\Models\Order;
use App\Notifications\OrderConfirmedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * SendOrderNotificationsJob — Sends order confirmation to the user.
 *
 * Retries up to 5 times with 30-second backoff (handles SMTP timeouts).
 */
class SendOrderNotificationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 5;
    public int $backoff = 30;

    public function __construct(public readonly Order $order) {}

    public function handle(): void
    {
        $this->order->user->notify(new OrderConfirmedNotification($this->order));

        Log::info("Order confirmation sent for order #{$this->order->order_number}");
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("Order notification failed for order #{$this->order->order_number}: {$exception->getMessage()}");
    }
}
