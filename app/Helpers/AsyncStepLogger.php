<?php

namespace App\Helpers;

use App\Jobs\AsyncLogJob;
use Illuminate\Support\Facades\Log;

class AsyncStepLogger
{

    private array $timers = [];

    private array $sharedContext = [];

    public function __construct(
        public string $channel = 'checkout',
    ) {}

    public function withContext(array $context): self
    {
        $this->sharedContext = $context;
        return $this;
    }

    public function addContext(array $context): self
    {
        $this->sharedContext = array_merge($this->sharedContext, $context);
        return $this;
    }

    public function start(string $stepName, array $context = []): void
    {
        $this->timers[$stepName] = microtime(true);

        if (!empty($context)) {
            $this->dispatch('info', "⏱ {$stepName}: starting", array_merge([
                'step_name' => $stepName,
                'status'    => 'started',
            ], $context));
        }
    }

    public function end(string $stepName, string $status = 'success', array $context = []): void
    {
        $durationMs = 0;
        if (isset($this->timers[$stepName])) {
            $durationMs = (int) round((microtime(true) - $this->timers[$stepName]) * 1000);
            unset($this->timers[$stepName]);
        }

        $level = ($status === 'success') ? 'info' : 'error';

        $this->dispatch($level, "✓ {$stepName}: {$status} ({$durationMs}ms)", array_merge([
            'step_name'   => $stepName,
            'status'      => $status,
            'duration_ms' => $durationMs,
        ], $context));
    }

    public function step(string $stepName, string $status, int $durationMs, array $context = []): void
    {
        $level = ($status === 'success') ? 'info' : 'error';

        $this->dispatch($level, "✓ {$stepName}: {$status} ({$durationMs}ms)", array_merge([
            'step_name'   => $stepName,
            'status'      => $status,
            'duration_ms' => $durationMs,
        ], $context));
    }

    private function dispatch(string $level, string $message, array $context): void
    {
        AsyncLogJob::dispatch(
            $this->channel,
            $level,
            $message,
            array_merge($this->sharedContext, $context)
        );
    }
}

if (!function_exists('async_step')) {
    function async_step(string $channel, string $level, string $message, array $context = []): void
    {
        AsyncLogJob::dispatch($channel, $level, $message, $context);
    }
}
