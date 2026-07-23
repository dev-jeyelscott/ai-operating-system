<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class AssignRequestContext
{
    private const HEADER = 'X-Request-ID';

    /**
     * Assign a safe correlation ID, expose it in the response, and emit a
     * structured completion event for the HTTP request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = hrtime(true);
        $requestId = $this->resolveRequestId($request);

        $request->attributes->set('request_id', $requestId);

        Log::shareContext([
            'request_id' => $requestId,
            'environment' => app()->environment(),
            'release' => config('app.release'),
        ]);

        $response = $next($request);

        $response->headers->set(self::HEADER, $requestId);

        Log::info('http.request.completed', [
            'method' => $request->method(),
            'path' => $request->path(),
            'route' => $request->route()?->getName(),
            'status' => $response->getStatusCode(),
            'duration_ms' => round(
                (hrtime(true) - $startedAt) / 1_000_000,
                2,
            ),
            'user_id' => $request->user()?->getAuthIdentifier(),
        ]);

        return $response;
    }

    /**
     * Accept a bounded printable request ID or generate a UUID when the
     * incoming value is absent or unsafe.
     */
    private function resolveRequestId(Request $request): string
    {
        $candidate = trim(
            (string) $request->header(self::HEADER),
        );

        if (
            preg_match(
                '/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/',
                $candidate,
            ) === 1
        ) {
            return $candidate;
        }

        return (string) Str::uuid();
    }
}
