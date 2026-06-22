<?php

namespace App\Jobs\Middleware;

use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

class PropagateCorrelationId
{

    protected string $correlationId;

    public function __construct(?string $correlationId = null)
    {
        $this->correlationId = $correlationId ?? config('app.current_correlation_id', '');
    }

    public function handle(object $job, \Closure $next): void
    {

        if ($this->correlationId) {
            Log::withContext([
                'correlation_id' => $this->correlationId,
                'job_class'      => get_class($job),
            ]);
        }

        $next($job);
    }
}
