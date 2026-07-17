<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AddRequestContext
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->requestId($request);
        $startedAt = hrtime(true);

        Context::add([
            'request_id' => $requestId,
            'method' => $request->method(),
            'path' => $request->path(),
        ]);

        $response = $next($request);
        $durationMs = round((hrtime(true) - $startedAt) / 1_000_000, 2);

        $response->headers->set('X-Request-ID', $requestId);
        $response->headers->set('Server-Timing', "app;dur={$durationMs}");

        if ($durationMs >= (float) config('observability.slow_request_threshold_ms', 1000)) {
            Log::warning('http.request.slow', [
                'status' => $response->getStatusCode(),
                'duration_ms' => $durationMs,
            ]);
        }

        return $response;
    }

    private function requestId(Request $request): string
    {
        $candidate = (string) $request->header('X-Request-ID', '');

        if (preg_match('/^[A-Za-z0-9._-]{8,100}$/', $candidate) === 1) {
            return $candidate;
        }

        return (string) Str::uuid();
    }
}
