<?php

declare(strict_types=1);

namespace App\Infrastructure\Integrations\Notion;

use App\Application\Integrations\Contracts\NotionPublicationClient;
use App\Application\Integrations\Data\NotionDataSource;
use App\Application\Integrations\Data\NotionPage;
use App\Application\Integrations\NotionPublicationException;
use App\Domain\Integrations\IntegrationCredentialSecret;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/** HTTP adapter that validates only the fields the application consumes. */
final readonly class HttpNotionPublicationClient implements NotionPublicationClient
{
    private const MANAGED_BODY_PREFIX = '[AIOS managed roadmap content]';

    public function retrieveDataSource(IntegrationCredentialSecret $credential, string $dataSourceId): NotionDataSource
    {
        $response = $this->send($credential, 'get', 'data_sources/'.$dataSourceId);
        $body = $this->successfulJson($response);

        if (($body['object'] ?? null) !== 'data_source' || ! is_array($body['properties'] ?? null)) {
            throw new NotionPublicationException('malformed_response', false, $this->requestId($response));
        }

        return new NotionDataSource(
            id: $this->requiredId($body['id'] ?? null, $response),
            name: $this->nullableString($body['name'] ?? null),
            properties: $body['properties'],
            providerRequestId: $this->requestId($response),
        );
    }

    public function findPagesByTicketKey(IntegrationCredentialSecret $credential, string $dataSourceId, string $ticketKey): array
    {
        $pages = [];
        $cursor = null;

        do {
            $payload = [
                'filter' => [
                    'property' => 'Ticket ID',
                    'rich_text' => ['equals' => $ticketKey],
                ],
                'page_size' => 100,
            ];
            if ($cursor !== null) {
                $payload['start_cursor'] = $cursor;
            }
            $response = $this->send($credential, 'post', 'data_sources/'.$dataSourceId.'/query', $payload);
            $body = $this->successfulJson($response);
            if (($body['object'] ?? null) !== 'list' || ! is_array($body['results'] ?? null)) {
                throw new NotionPublicationException('malformed_response', false, $this->requestId($response));
            }
            foreach ($body['results'] as $result) {
                if (! is_array($result)) {
                    throw new NotionPublicationException('malformed_response', false, $this->requestId($response));
                }
                $pages[] = $this->pageFromBody($result, $response, $dataSourceId);
            }
            $hasMore = $body['has_more'] ?? false;
            $cursor = $hasMore === true && is_string($body['next_cursor'] ?? null)
                ? $body['next_cursor']
                : null;
            if ($hasMore === true && $cursor === null) {
                throw new NotionPublicationException('malformed_response', false, $this->requestId($response));
            }
        } while ($cursor !== null);

        return $pages;
    }

    public function createPage(IntegrationCredentialSecret $credential, string $dataSourceId, array $properties, string $body): NotionPage
    {
        $response = $this->send($credential, 'post', 'pages', [
            'parent' => ['type' => 'data_source_id', 'data_source_id' => $dataSourceId],
            'properties' => $properties,
            'children' => $this->bodyBlocks($body),
        ]);

        return $this->pageFromBody($this->successfulJson($response), $response, $dataSourceId);
    }

    public function updatePage(IntegrationCredentialSecret $credential, string $pageId, array $properties, string $body): NotionPage
    {
        $response = $this->send($credential, 'patch', 'pages/'.$pageId, [
            'properties' => $properties,
        ]);
        $page = $this->pageFromBody($this->successfulJson($response), $response, '');

        $managedBlockId = $this->managedBlockId($credential, $pageId);
        if ($managedBlockId !== null) {
            $blockResponse = $this->send($credential, 'patch', 'blocks/'.$managedBlockId, [
                'paragraph' => ['rich_text' => $this->textFragments($this->managedBody($body))],
            ]);
            $updatedBlock = $this->successfulJson($blockResponse);
            if (($updatedBlock['object'] ?? null) !== 'block' || $this->requiredId($updatedBlock['id'] ?? null, $blockResponse) !== $managedBlockId) {
                throw new NotionPublicationException('malformed_response', false, $this->requestId($blockResponse));
            }

            return $page;
        }

        $childrenResponse = $this->send($credential, 'patch', 'blocks/'.$pageId.'/children', [
            'children' => $this->bodyBlocks($body),
        ]);
        $children = $this->successfulJson($childrenResponse);
        if (($children['object'] ?? null) !== 'list' || ! is_array($children['results'] ?? null)) {
            throw new NotionPublicationException('malformed_response', false, $this->requestId($childrenResponse));
        }

        return $page;
    }

    public function retrievePage(IntegrationCredentialSecret $credential, string $pageId): NotionPage
    {
        $response = $this->send($credential, 'get', 'pages/'.$pageId);

        return $this->pageFromBody($this->successfulJson($response), $response, '');
    }

    public function retrievePageBody(IntegrationCredentialSecret $credential, string $pageId): string
    {
        $cursor = null;
        $lines = [];
        $managedBody = null;
        do {
            $payload = ['page_size' => 100];
            if ($cursor !== null) {
                $payload['start_cursor'] = $cursor;
            }
            $response = $this->send($credential, 'get', 'blocks/'.$pageId.'/children', $payload);
            $body = $this->successfulJson($response);
            if (($body['object'] ?? null) !== 'list' || ! is_array($body['results'] ?? null)) {
                throw new NotionPublicationException('malformed_response', false, $this->requestId($response));
            }
            foreach ($body['results'] as $block) {
                if (! is_array($block)) {
                    throw new NotionPublicationException('malformed_response', false, $this->requestId($response));
                }
                $plainText = $this->blockPlainText($block);
                if (str_starts_with($plainText, self::MANAGED_BODY_PREFIX."\n")) {
                    $managedBody = substr($plainText, strlen(self::MANAGED_BODY_PREFIX."\n"));

                    continue;
                }
                if ($plainText !== '') {
                    $lines[] = $plainText;
                }
            }
            $hasMore = $body['has_more'] ?? false;
            $cursor = $hasMore === true && is_string($body['next_cursor'] ?? null) ? $body['next_cursor'] : null;
            if ($hasMore === true && $cursor === null) {
                throw new NotionPublicationException('malformed_response', false, $this->requestId($response));
            }
        } while ($cursor !== null);

        return $managedBody ?? implode("\n", $lines);
    }

    /** @param array<string, mixed> $payload */
    private function send(IntegrationCredentialSecret $credential, string $method, string $uri, array $payload = []): Response
    {
        try {
            return $this->request($credential)->{$method}($uri, $payload);
        } catch (ConnectionException) {
            throw new NotionPublicationException('provider_unavailable', true);
        }
    }

    private function request(IntegrationCredentialSecret $credential): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.notion.base_url'), '/').'/')
            ->acceptJson()
            ->withToken($credential->reveal())
            ->withHeaders(['Notion-Version' => (string) config('services.notion.version')])
            ->connectTimeout(max(1, (int) config('services.notion.connect_timeout_seconds', 3)))
            ->timeout(max(1, (int) config('services.notion.timeout_seconds', 8)))
            ->retry(
                max(1, (int) config('services.notion.retry_attempts', 3)),
                static function (int $attempt, Exception $exception): int {
                    if ($exception instanceof RequestException && $exception->response->status() === 429) {
                        $retryAfter = (int) $exception->response->header('Retry-After');
                        if ($retryAfter > 0) {
                            return $retryAfter * 1000;
                        }
                    }

                    return (int) min(250 * (2 ** ($attempt - 1)), 2000);
                },
                static function (Throwable $exception): bool {
                    if ($exception instanceof ConnectionException) {
                        return true;
                    }
                    if (! $exception instanceof RequestException) {
                        return false;
                    }
                    if ($exception->response->status() === 429) {
                        $retryAfter = (int) $exception->response->header('Retry-After');

                        return $retryAfter === 0 || $retryAfter <= 5;
                    }

                    return in_array($exception->response->status(), [409, 500, 502, 503, 504], true);
                },
                throw: false,
            );
    }

    /** @return array<string, mixed> */
    private function successfulJson(Response $response): array
    {
        if (! $response->successful()) {
            throw new NotionPublicationException(
                category: $this->category($response),
                retryable: in_array($response->status(), [409, 429, 500, 502, 503, 504], true),
                providerRequestId: $this->requestId($response),
            );
        }
        $body = $response->json();
        if (! is_array($body)) {
            throw new NotionPublicationException('malformed_response', false, $this->requestId($response));
        }

        return $body;
    }

    /** @param array<string, mixed> $body */
    private function pageFromBody(array $body, Response $response, string $fallbackDataSourceId): NotionPage
    {
        if (($body['object'] ?? null) !== 'page' || ! is_array($body['properties'] ?? null) || ! is_string($body['url'] ?? null)) {
            throw new NotionPublicationException('malformed_response', false, $this->requestId($response));
        }
        $parent = is_array($body['parent'] ?? null) ? $body['parent'] : [];
        $dataSourceId = $parent['data_source_id'] ?? $fallbackDataSourceId;
        if (! is_string($dataSourceId) || trim($dataSourceId) === '') {
            throw new NotionPublicationException('malformed_response', false, $this->requestId($response));
        }

        return new NotionPage($this->requiredId($body['id'] ?? null, $response), $dataSourceId, $body['url'], $body['properties'], $this->requestId($response));
    }

    private function requiredId(mixed $value, Response $response): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new NotionPublicationException('malformed_response', false, $this->requestId($response));
        }

        return trim($value);
    }

    private function category(Response $response): string
    {
        return match ($response->status()) {
            401 => 'invalid_token', 403 => 'forbidden', 404 => 'not_found', 409 => 'conflict', 429 => 'rate_limited', 500, 502, 503, 504 => 'provider_unavailable', default => 'provider_error',
        };
    }

    private function requestId(Response $response): ?string
    {
        $requestId = trim((string) ($response->header('x-request-id') ?: $response->json('request_id')));

        return $requestId === '' ? null : mb_substr($requestId, 0, 255);
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, 255) : null;
    }

    /** @param array<string, mixed> $block */
    private function blockPlainText(array $block): string
    {
        $type = $block['type'] ?? null;
        if (! is_string($type) || ! is_array($block[$type] ?? null)) {
            return '';
        }
        $richText = $block[$type]['rich_text'] ?? null;
        if (! is_array($richText)) {
            return '';
        }

        return collect($richText)
            ->map(fn (mixed $fragment): string => is_array($fragment) && is_string($fragment['plain_text'] ?? null) ? $fragment['plain_text'] : '')
            ->implode('');
    }

    /** @return list<array<string, mixed>> */
    private function bodyBlocks(string $body): array
    {
        return [[
            'object' => 'block',
            'type' => 'paragraph',
            'paragraph' => ['rich_text' => $this->textFragments($this->managedBody($body))],
        ]];
    }

    private function managedBody(string $body): string
    {
        return self::MANAGED_BODY_PREFIX."\n".$body;
    }

    private function managedBlockId(IntegrationCredentialSecret $credential, string $pageId): ?string
    {
        $cursor = null;
        do {
            $payload = ['page_size' => 100];
            if ($cursor !== null) {
                $payload['start_cursor'] = $cursor;
            }
            $response = $this->send($credential, 'get', 'blocks/'.$pageId.'/children', $payload);
            $body = $this->successfulJson($response);
            if (($body['object'] ?? null) !== 'list' || ! is_array($body['results'] ?? null)) {
                throw new NotionPublicationException('malformed_response', false, $this->requestId($response));
            }
            foreach ($body['results'] as $block) {
                if (! is_array($block)) {
                    throw new NotionPublicationException('malformed_response', false, $this->requestId($response));
                }
                if (str_starts_with($this->blockPlainText($block), self::MANAGED_BODY_PREFIX."\n")) {
                    return $this->requiredId($block['id'] ?? null, $response);
                }
            }
            $hasMore = $body['has_more'] ?? false;
            $cursor = $hasMore === true && is_string($body['next_cursor'] ?? null) ? $body['next_cursor'] : null;
            if ($hasMore === true && $cursor === null) {
                throw new NotionPublicationException('malformed_response', false, $this->requestId($response));
            }
        } while ($cursor !== null);

        return null;
    }

    /** @return list<array{type:string,text:array{content:string}}> */
    private function textFragments(string $body): array
    {
        if ($body === '') {
            return [];
        }

        $fragments = [];
        for ($offset = 0; $offset < mb_strlen($body); $offset += 1900) {
            $fragments[] = [
                'type' => 'text',
                'text' => ['content' => mb_substr($body, $offset, 1900)],
            ];
        }

        return $fragments;
    }
}
