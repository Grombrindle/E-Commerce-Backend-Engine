<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Cache;

class CacheHelper
{
    private static ?bool $_supportsTags = null;

    public static function supportsTags(): bool
    {
        if (self::$_supportsTags === null) {
            try {
                $storeClass = get_class(Cache::store()->getStore());
                self::$_supportsTags = in_array($storeClass, [
                    'Illuminate\Cache\RedisStore',
                    'Illuminate\Cache\MemcachedStore',
                    'Illuminate\Cache\DatabaseStore',
                ]);
            } catch (\Throwable $e) {
                self::$_supportsTags = false;
            }
        }
        return self::$_supportsTags;
    }

    public static function get(string $key, array $tags = []): mixed
    {
        try {
            if (! empty($tags) && self::supportsTags()) {
                return Cache::tags($tags)->get($key);
            }
            return Cache::get($key);
        } catch (\Throwable $e) {
            return Cache::get($key);
        }
    }

    public static function put(string $key, mixed $value, int $ttl, array $tags = []): void
    {
        try {
            if (! empty($tags) && self::supportsTags()) {
                Cache::tags($tags)->put($key, $value, $ttl);
            } else {
                Cache::put($key, $value, $ttl);
            }
        } catch (\Throwable $e) {
            Cache::put($key, $value, $ttl);
        }
    }

    public static function flush(array $tags = []): void
    {
        try {
            if (! empty($tags) && self::supportsTags()) {
                Cache::tags($tags)->flush();
            } else {
                foreach ($tags as $tag) {
                    Cache::forget($tag);
                }
            }
        } catch (\Throwable $e) {

        }
    }

    public static function remember(string $key, int $ttl, callable $callback, array $tags = []): mixed
    {
        $cached = self::get($key, $tags);
        if ($cached !== null) {
            return $cached;
        }

        $result = $callback();
        self::put($key, $result, $ttl, $tags);
        return $result;
    }
}
