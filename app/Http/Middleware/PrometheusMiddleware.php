<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;

class PrometheusMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $start = microtime(true);

        try {
            Redis::incr('metrics:http_active');
        } catch (\Throwable $e) {

        }

        $response = $next($request);

        $duration = microtime(true) - $start;

        try {

            $method = $request->method();
            $route = $request->path();
            $statusCode = $response->getStatusCode();
            $key = "{$method}|{$route}|{$statusCode}";

            Redis::hincrby('metrics:http_requests', $key, 1);

            $durationKey = "{$method}|{$route}";
            Redis::hset('metrics:http_duration', $durationKey, $duration);

            if ($statusCode >= 400) {
                $errorKey = "{$method}|{$route}|{$statusCode}";
                Redis::hincrby('metrics:http_errors', $errorKey, 1);
            }

            Redis::decr('metrics:http_active');
            if ((int) Redis::get('metrics:http_active') < 0) {
                Redis::set('metrics:http_active', 0);
            }
        } catch (\Throwable $e) {

        }

        return $response;
    }
}
