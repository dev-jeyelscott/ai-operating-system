<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ApiErrorResponse
{
    /**
     * Build the canonical JSON error envelope used by APIs and explicit
     * JSON requests.
     *
     * @param array<string, mixed> $details
     */
    public static function make(
        Request $request,
        string $code,
        string $message,
        int $status,
        bool $retryable = false,
        array $details = [],
        ?int $retryAfterSeconds = null,
    ): JsonResponse {
        $requestId = (string) $request->attributes->get(
            'request_id',
            '',
        );

        $response = response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'retryable' => $retryable,
                'request_id' => $requestId,
                'details' => $details,
            ],
        ], $status);

        if ($requestId !== '') {
            $response->headers->set('X-Request-ID', $requestId);
        }

        if ($retryAfterSeconds !== null) {
            $response->headers->set(
                'Retry-After',
                (string) $retryAfterSeconds,
            );
        }

        return $response;
    }
}
