<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class AsyncLogJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(
        public string $channel,
        public string $level,
        public string $message,
        public array  $context = [],
    ) {
        $this->onQueue('logs');
        $this->onConnection('redis');
    }

    public function handle(): void
    {
        Log::channel($this->channel)->log(
            $this->level,
            $this->message,
            $this->context
        );
    }
}
