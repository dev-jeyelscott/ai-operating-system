<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Support\Security\SensitiveValueRedactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ApiErrorResponse
{
    /**
     * Build the canonical JSON error envelope.
     *
     * Public error details are sanitized as a final defense in depth. Field
     * names are retained so validation clients can still identify inputs.
     *
     * @param  array<string, mixed>  $details
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

        $redactor = app(SensitiveValueRedactor::class);

        $safeMessage = $redactor->message($message);

        if (
            $safeMessage
            === SensitiveValueRedactor::REDACTION_FAILED
        ) {
            $safeMessage = 'The request could not be completed safely.';
        }

        $response = response()->json([
            'error' => [
                'code' => $code,
                'message' => $safeMessage,
                'retryable' => $retryable,
                'request_id' => $requestId,
                'details' => $redactor->redactValues(
                    $details,
                ),
            ],
        ], $status);

        if ($requestId !== '') {
            $response->headers->set(
                'X-Request-ID',
                $requestId,
            );
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
