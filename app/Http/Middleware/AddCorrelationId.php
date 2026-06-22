<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AddCorrelationId
{

    public const HEADER_NAME = 'X-Correlation-Id';

    public function handle(Request $request, Closure $next): Response
    {

        $correlationId = $request->header(self::HEADER_NAME) ?? (string) Str::uuid();

        $request->attributes->set('correlation_id', $correlationId);

        $this->setLogContext($correlationId);

        config(['app.current_correlation_id' => $correlationId]);

        $response = $next($request);

        $response->headers->set(self::HEADER_NAME, $correlationId);

        return $response;
    }

    private function setLogContext(string $correlationId): void
    {

        logger()->withContext([
            'correlation_id' => $correlationId,
        ]);
    }
}
