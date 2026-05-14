<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;

class SemaphoreService
{
    /**
     * Atomically acquires a semaphore slot.
     * Uses a Lua script to guarantee the check-and-increment is atomic
     * (no race condition on the semaphore itself).
     */
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

        return (bool) Redis::eval($luaScript, 1, $key, $maxConcurrent, $ttlSeconds);
    }

    /**
     * Release a semaphore slot (decrement counter, never below 0).
     */
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
    }
}
