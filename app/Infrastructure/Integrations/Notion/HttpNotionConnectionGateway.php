<?php

declare(strict_types=1);

namespace App\Infrastructure\Integrations\Notion;

use App\Application\Integrations\Contracts\NotionConnectionGateway;
use App\Application\Integrations\NotionConnectionTestResult;
use App\Application\Planning\Notion\NotionTicketSchema;
use App\Domain\Integrations\IntegrationCredentialSecret;
use App\Domain\Integrations\NotionConnectionFailureCode;
use App\Domain\Integrations\NotionDatabaseId;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Tests Notion access using bounded, read-only HTTP requests.
 */
final readonly class HttpNotionConnectionGateway implements NotionConnectionGateway
{
    public function __construct(private NotionTicketSchema $ticketSchema) {}

    /**
     * Validate the token workspace and retrieve the configured database.
     */
    public function test(
        IntegrationCredentialSecret $credential,
        NotionDatabaseId $databaseId,
        ?string $expectedWorkspaceId,
        ?string $selectedDataSourceId = null,
    ): NotionConnectionTestResult {
        try {
            $selfResponse = $this->request($credential)
                ->get('users/me');
        } catch (ConnectionException) {
            return NotionConnectionTestResult::failed(
                failureCode: NotionConnectionFailureCode::ProviderUnavailable,
                databaseId: $databaseId->value(),
            );
        }

        if (! $selfResponse->successful()) {
            return NotionConnectionTestResult::failed(
                failureCode: $this->failureCode(
                    response: $selfResponse,
                    databaseLookup: false,
                ),
                databaseId: $databaseId->value(),
                providerRequestId: $this->requestId($selfResponse),
            );
        }

        $workspaceId = $this->normalizedUuid(
            $selfResponse->json('bot.workspace_id'),
        );

        /*
         * The MVP expects an internal Notion connection whose bot response
         * includes a stable workspace identifier.
         */
        if (
            $selfResponse->json('type') !== 'bot'
            || $workspaceId === null
        ) {
            return NotionConnectionTestResult::failed(
                failureCode: NotionConnectionFailureCode::InvalidProviderResponse,
                databaseId: $databaseId->value(),
                providerRequestId: $this->requestId($selfResponse),
            );
        }

        $workspaceName = $this->nullableString(
            $selfResponse->json('bot.workspace_name'),
        );

        if (
            $expectedWorkspaceId !== null
            && ! hash_equals(
                strtolower($expectedWorkspaceId),
                strtolower($workspaceId),
            )
        ) {
            return NotionConnectionTestResult::failed(
                failureCode: NotionConnectionFailureCode::WorkspaceMismatch,
                databaseId: $databaseId->value(),
                providerRequestId: $this->requestId($selfResponse),
                workspaceId: $workspaceId,
                workspaceName: $workspaceName,
            );
        }

        try {
            $databaseResponse = $this->request($credential)
                ->get('databases/'.$databaseId->value());
        } catch (ConnectionException) {
            return NotionConnectionTestResult::failed(
                failureCode: NotionConnectionFailureCode::ProviderUnavailable,
                databaseId: $databaseId->value(),
                workspaceId: $workspaceId,
                workspaceName: $workspaceName,
            );
        }

        if (! $databaseResponse->successful()) {
            return NotionConnectionTestResult::failed(
                failureCode: $this->failureCode(
                    response: $databaseResponse,
                    databaseLookup: true,
                ),
                databaseId: $databaseId->value(),
                providerRequestId: $this->requestId(
                    $databaseResponse,
                ),
                workspaceId: $workspaceId,
                workspaceName: $workspaceName,
            );
        }

        $returnedDatabaseId = $this->normalizedUuid(
            $databaseResponse->json('id'),
        );

        if (
            $returnedDatabaseId === null
            || ! hash_equals(
                $databaseId->value(),
                $returnedDatabaseId,
            )
        ) {
            return NotionConnectionTestResult::failed(
                failureCode: NotionConnectionFailureCode::InvalidProviderResponse,
                databaseId: $databaseId->value(),
                providerRequestId: $this->requestId(
                    $databaseResponse,
                ),
                workspaceId: $workspaceId,
                workspaceName: $workspaceName,
            );
        }

        $dataSources = $this->dataSources($databaseResponse);

        if ($dataSources === []) {
            return NotionConnectionTestResult::failed(
                failureCode: NotionConnectionFailureCode::InvalidProviderResponse,
                databaseId: $databaseId->value(),
                providerRequestId: $this->requestId($databaseResponse),
                workspaceId: $workspaceId,
                workspaceName: $workspaceName,
            );
        }

        if ($selectedDataSourceId === null && count($dataSources) > 1) {
            return NotionConnectionTestResult::dataSourceSelectionRequired(
                databaseId: $databaseId->value(),
                candidates: $dataSources,
                providerRequestId: $this->requestId($databaseResponse),
                workspaceId: $workspaceId,
                workspaceName: $workspaceName,
            );
        }

        $dataSource = $selectedDataSourceId === null
            ? $dataSources[0]
            : collect($dataSources)->first(fn (array $candidate): bool => hash_equals($selectedDataSourceId, $candidate['id']));
        if ($dataSource === null) {
            return NotionConnectionTestResult::failed(NotionConnectionFailureCode::InvalidProviderResponse, $databaseId->value(), $this->requestId($databaseResponse), $workspaceId, $workspaceName);
        }

        try {
            $dataSourceResponse = $this->request($credential)
                ->get('data_sources/'.$dataSource['id']);
        } catch (ConnectionException) {
            return NotionConnectionTestResult::failed(NotionConnectionFailureCode::ProviderUnavailable, $databaseId->value(), null, $workspaceId, $workspaceName);
        }
        if (! $dataSourceResponse->successful() || $dataSourceResponse->json('object') !== 'data_source' || ! hash_equals($dataSource['id'], (string) $this->normalizedUuid($dataSourceResponse->json('id')))) {
            return NotionConnectionTestResult::failed($dataSourceResponse->successful() ? NotionConnectionFailureCode::InvalidProviderResponse : $this->failureCode($dataSourceResponse, true), $databaseId->value(), $this->requestId($dataSourceResponse), $workspaceId, $workspaceName);
        }
        $properties = $dataSourceResponse->json('properties');
        if (! is_array($properties) || ! $this->ticketSchema->propertiesAreReady($properties)) {
            return NotionConnectionTestResult::failed(
                NotionConnectionFailureCode::SchemaIncompatible,
                $databaseId->value(),
                $this->requestId($dataSourceResponse),
                $workspaceId,
                $workspaceName,
            );
        }

        return NotionConnectionTestResult::connected(
            workspaceId: $workspaceId,
            workspaceName: $workspaceName,
            databaseId: $databaseId->value(),
            databaseName: $this->databaseName($databaseResponse),
            dataSourceId: $dataSource['id'],
            dataSourceName: $dataSource['name'],
            providerRequestId: $this->requestId($dataSourceResponse)
                ?? $this->requestId($databaseResponse)
                ?? $this->requestId($selfResponse),
        );
    }

    /**
     * Build a fresh authenticated request without exposing the token.
     */
    private function request(
        IntegrationCredentialSecret $credential,
    ): PendingRequest {
        return Http::baseUrl(
            rtrim(
                (string) config('services.notion.base_url'),
                '/',
            ).'/',
        )
            ->acceptJson()
            ->withToken($credential->reveal())
            ->withHeaders([
                'Notion-Version' => (string) config('services.notion.version'),
            ])
            ->connectTimeout(max(
                1,
                (int) config(
                    'services.notion.connect_timeout_seconds',
                    3,
                ),
            ))
            ->timeout(max(
                1,
                (int) config(
                    'services.notion.timeout_seconds',
                    8,
                ),
            ))
            ->retry(
                max(
                    1,
                    (int) config(
                        'services.notion.retry_attempts',
                        3,
                    ),
                ),
                static function (
                    int $attempt,
                    Exception $exception,
                ): int {
                    if (
                        $exception instanceof RequestException
                        && $exception->response->status() === 429
                    ) {
                        $retryAfter = (int) $exception->response->header(
                            'Retry-After',
                        );

                        if ($retryAfter > 0) {
                            return $retryAfter * 1000;
                        }
                    }

                    return (int) min(
                        250 * (2 ** ($attempt - 1)),
                        2000,
                    );
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

                    /*
                     * Do not sleep for an unbounded Retry-After period inside a
                     * synchronous user request. Surface long limits to the user.
                     */
                    if ($status === 429) {
                        $retryAfter = (int) $exception->response->header(
                            'Retry-After',
                        );

                        return $retryAfter === 0
                            || $retryAfter <= 5;
                    }

                    return in_array($status, [
                        409,
                        500,
                        502,
                        503,
                        504,
                    ], true);
                },
                throw: false,
            );
    }

    /**
     * Map provider statuses into stable application failure categories.
     */
    private function failureCode(
        Response $response,
        bool $databaseLookup,
    ): NotionConnectionFailureCode {
        return match ($response->status()) {
            401 => NotionConnectionFailureCode::InvalidToken,

            403 => NotionConnectionFailureCode::MissingReadCapability,

            404 => $databaseLookup
                ? NotionConnectionFailureCode::DatabaseNotShared
                : NotionConnectionFailureCode::ConnectionFailed,

            429 => NotionConnectionFailureCode::RateLimited,

            500, 502, 503, 504 => NotionConnectionFailureCode::ProviderUnavailable,

            default => NotionConnectionFailureCode::ConnectionFailed,
        };
    }

    /**
     * Read a provider request ID without storing response bodies.
     */
    private function requestId(Response $response): ?string
    {
        $headerRequestId = trim(
            $response->header('x-request-id'),
        );

        if ($headerRequestId !== '') {
            return substr($headerRequestId, 0, 255);
        }

        $bodyRequestId = $response->json('request_id');

        return is_string($bodyRequestId)
            && trim($bodyRequestId) !== ''
                ? substr(trim($bodyRequestId), 0, 255)
                : null;
    }

    /**
     * Normalize a provider UUID or return null for malformed data.
     */
    private function normalizedUuid(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $hexadecimal = strtolower(
            str_replace('-', '', trim($value)),
        );

        if (
            preg_match(
                '/\A[0-9a-f]{32}\z/D',
                $hexadecimal,
            ) !== 1
        ) {
            return null;
        }

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hexadecimal, 0, 8),
            substr($hexadecimal, 8, 4),
            substr($hexadecimal, 12, 4),
            substr($hexadecimal, 16, 4),
            substr($hexadecimal, 20, 12),
        );
    }

    /**
     * Return a trimmed nullable provider string.
     */
    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized !== ''
            ? mb_substr($normalized, 0, 255)
            : null;
    }

    /**
     * Select the database's only data source so later schema and ticket queries
     * can target the Notion data-source API rather than the database container.
     *
     * @return list<array{id: string, name: string|null}>
     */
    private function dataSources(Response $response): array
    {
        $dataSources = $response->json('data_sources', []);

        if (! is_array($dataSources) || $dataSources === []) {
            return [];
        }
        $candidates = [];
        foreach ($dataSources as $dataSource) {
            if (! is_array($dataSource) || ($id = $this->normalizedUuid($dataSource['id'] ?? null)) === null) {
                return [];
            }
            $candidates[] = ['id' => $id, 'name' => $this->nullableString($dataSource['name'] ?? null)];
        }

        return $candidates;
    }

    /**
     * Extract the database title from Notion rich-text fragments.
     */
    private function databaseName(Response $response): ?string
    {
        $title = $response->json('title', []);

        if (! is_array($title)) {
            return null;
        }

        $fragments = [];

        foreach ($title as $fragment) {
            if (
                is_array($fragment)
                && isset($fragment['plain_text'])
                && is_string($fragment['plain_text'])
            ) {
                $fragments[] = $fragment['plain_text'];
            }
        }

        return $this->nullableString(
            implode('', $fragments),
        );
    }
}
