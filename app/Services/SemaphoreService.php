<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;

class SemaphoreService
{

    public function acquire(string $key, int $maxConcurrent, int $ttlSeconds = 30): bool
    {
        $luaScript = <<<'LUA'
        local current = tonumber(redis.call('GET', KEYS[1]) or 0)
        if current >= tonumber(ARGV[1]) then
            return 0
        end
        redis.call('INCR', KEYS[1])
        redis.call('EXPIRE', KEYS[1], ARGV[2])
        return 1
        LUA;

        $result = (bool) Redis::eval($luaScript, 1, $key, $maxConcurrent, $ttlSeconds);

        $this->recordSemaphoreMetrics($key, $maxConcurrent);
        $this->recordMetric('semaphore_acquires', "{$key}|" . ($result ? 'acquired' : 'rejected'));

        return $result;
    }

    public function release(string $key): void
    {
        $luaScript = <<<'LUA'
        local current = tonumber(redis.call('GET', KEYS[1]) or 0)
        if current > 0 then
            redis.call('DECR', KEYS[1])
        end
        return 1
        LUA;

        Redis::eval($luaScript, 1, $key);

        $this->recordSemaphoreMetricsFromStored($key);
    }

    private function recordSemaphoreMetricsFromStored(string $key): void
    {
        try {
            $maxConcurrent = (int) (Redis::hget('metrics:semaphore_max_concurrent', $key) ?: 0);
            if ($maxConcurrent > 0) {
                $this->recordSemaphoreMetrics($key, $maxConcurrent);
            }
        } catch (\Throwable $e) {

        }
    }

    private function recordSemaphoreMetrics(string $key, int $maxConcurrent): void
    {
        try {
            $current = (int) (Redis::get($key) ?: 0);
            $ratio = $maxConcurrent > 0 ? min($current / $maxConcurrent, 1.0) : 0;

            Redis::hset('metrics:semaphore_current_usage', $key, $current);
            Redis::hset('metrics:semaphore_max_concurrent', $key, $maxConcurrent);
            Redis::hset('metrics:semaphore_utilization_ratio', $key, $ratio);
        } catch (\Throwable $e) {

        }
    }

    private function recordMetric(string $key, string $labelValue): void
    {
        try {
            Redis::hincrby("metrics:{$key}", $labelValue, 1);
        } catch (\Throwable $e) {

        }
    }
}
