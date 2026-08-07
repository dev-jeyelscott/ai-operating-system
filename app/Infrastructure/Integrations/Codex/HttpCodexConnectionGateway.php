<?php

declare(strict_types=1);

namespace App\Infrastructure\Integrations\Codex;

use App\Application\Integrations\CodexConnectionTestResult;
use App\Application\Integrations\Contracts\CodexConnectionGateway;
use App\Domain\Integrations\CodexConnectionFailureCode;
use App\Domain\Integrations\IntegrationCredentialSecret;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Performs a bounded read-only Codex preflight against the OpenAI Models API.
 */
final readonly class HttpCodexConnectionGateway implements CodexConnectionGateway
{
    /**
     * Verify credential access to the configured model without executing work.
     */
    public function test(
        IntegrationCredentialSecret $credential,
        string $modelIdentifier,
    ): CodexConnectionTestResult {
        try {
            $response = $this->request($credential)
                ->get('models/'.rawurlencode($modelIdentifier));
        } catch (ConnectionException) {
            return CodexConnectionTestResult::failed(
                failureCode: CodexConnectionFailureCode::ProviderUnavailable,
                modelIdentifier: $modelIdentifier,
            );
        }

        if (! $response->successful()) {
            return CodexConnectionTestResult::failed(
                failureCode: $this->failureCode($response),
                modelIdentifier: $modelIdentifier,
                providerRequestId: $this->requestId($response),
            );
        }

        $returnedIdentifier = $response->json('id');

        if (
            ! is_string($returnedIdentifier)
            || ! hash_equals($modelIdentifier, $returnedIdentifier)
        ) {
            return CodexConnectionTestResult::failed(
                failureCode: CodexConnectionFailureCode::InvalidProviderResponse,
                modelIdentifier: $modelIdentifier,
                providerRequestId: $this->requestId($response),
            );
        }

        return CodexConnectionTestResult::connected(
            modelIdentifier: $modelIdentifier,
            providerRequestId: $this->requestId($response),
        );
    }

    /**
     * Build a fresh authenticated request without logging the credential.
     */
    private function request(
        IntegrationCredentialSecret $credential,
    ): PendingRequest {
        return Http::baseUrl(
            rtrim((string) config('services.codex.base_url'), '/').'/',
        )
            ->acceptJson()
            ->withoutRedirecting()
            ->withToken($credential->reveal())
            ->connectTimeout(max(
                1,
                (int) config('services.codex.connect_timeout_seconds', 3),
            ))
            ->timeout(max(
                1,
                (int) config('services.codex.timeout_seconds', 8),
            ))
            ->retry(
                max(1, (int) config('services.codex.retry_attempts', 2)),
                static function (
                    int $attempt,
                    Throwable $exception,
                ): int {
                    if (
                        $exception instanceof RequestException
                        && $exception->response->status() === 429
                    ) {
                        $retryAfter = (int) $exception->response->header(
                            'Retry-After',
                        );

                        if ($retryAfter > 0) {
                            return min($retryAfter, 5) * 1000;
                        }
                    }

                    return (int) min(250 * (2 ** ($attempt - 1)), 1500);
                },
                static function (
                    Throwable $exception,
                    PendingRequest $request,
                ): bool {
                    unset($request);

                    if ($exception instanceof ConnectionException) {
                        return true;
                    }

                    if (! $exception instanceof RequestException) {
                        return false;
                    }

                    $status = $exception->response->status();

                    if ($status === 429) {
                        $retryAfter = (int) $exception->response->header(
                            'Retry-After',
                        );

                        return $retryAfter === 0 || $retryAfter <= 5;
                    }

                    return in_array($status, [409, 500, 502, 503, 504], true);
                },
                throw: false,
            );
    }

    /**
     * Map provider statuses into stable application failure categories.
     */
    private function failureCode(Response $response): CodexConnectionFailureCode
    {
        return match ($response->status()) {
            401 => CodexConnectionFailureCode::InvalidCredential,
            403 => CodexConnectionFailureCode::MissingCapability,
            404 => CodexConnectionFailureCode::ModelUnavailable,
            429 => CodexConnectionFailureCode::RateLimited,
            500, 502, 503, 504 => CodexConnectionFailureCode::ProviderUnavailable,
            default => CodexConnectionFailureCode::ConnectionFailed,
        };
    }

    /**
     * Read only a bounded request identifier and never persist response bodies.
     */
    private function requestId(Response $response): ?string
    {
        $requestId = trim($response->header('x-request-id'));

        return $requestId !== ''
            ? mb_substr($requestId, 0, 255)
            : null;
    }
}
