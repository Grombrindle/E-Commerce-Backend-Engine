<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ThrottleOrders
{
    public function __construct(protected RateLimiter $limiter) {}

    public function handle(Request $request, Closure $next): Response
    {
        $key     = 'orders:' . ($request->user()?->id ?? $request->ip());
        $maxAttempts = 10;
        $decaySeconds = 60;

        if ($this->limiter->tooManyAttempts($key, $maxAttempts)) {
            $retryAfter = $this->limiter->availableIn($key);
            return response()->json([
                'success' => false,
                'message' => "Too many order requests. Please wait {$retryAfter} seconds.",
                'retry_after' => $retryAfter,
            ], 429);
        }

        $this->limiter->hit($key, $decaySeconds);
        return $next($request);
    }
}
