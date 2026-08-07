<?php

declare(strict_types=1);

use App\Application\Integrations\Contracts\NotionPublicationClient;
use App\Application\Integrations\NotionPublicationException;
use App\Domain\Integrations\IntegrationCredentialSecret;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set(['services.notion.base_url' => 'https://api.notion.com/v1', 'services.notion.version' => '2026-03-11', 'services.notion.retry_attempts' => 1]);
    Http::preventStrayRequests();
});

test('it retrieves a typed data source using the current Notion contract header', function (): void {
    Http::fake(['https://api.notion.com/v1/data_sources/source-1' => Http::response(['object' => 'data_source', 'id' => 'source-1', 'name' => 'Tickets', 'properties' => ['Ticket ID' => ['type' => 'rich_text']]], 200, ['x-request-id' => 'req-source'])]);

    $source = app(NotionPublicationClient::class)->retrieveDataSource(IntegrationCredentialSecret::from('secret_notion_abcdefghijklmnopqrstuvwxyz'), 'source-1');

    expect($source->id)->toBe('source-1')->and($source->providerRequestId)->toBe('req-source');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && $request->url() === 'https://api.notion.com/v1/data_sources/source-1' && $request->hasHeader('Notion-Version', '2026-03-11'));
});

test('it updates only the managed Notion body block and preserves other ticket content', function (): void {
    $page = ['object' => 'page', 'id' => 'page-1', 'url' => 'https://www.notion.so/page-1', 'parent' => ['data_source_id' => 'source-1'], 'properties' => ['Ticket ID' => ['rich_text' => [['plain_text' => 'ticket-1']]]]];
    Http::fake([
        'https://api.notion.com/v1/pages/page-1' => Http::response($page, 200, ['x-request-id' => 'req-page']),
        'https://api.notion.com/v1/blocks/page-1/children*' => Http::response(['object' => 'list', 'results' => [
            ['object' => 'block', 'id' => 'qa-block', 'type' => 'paragraph', 'paragraph' => ['rich_text' => [['plain_text' => 'Existing QA evidence']]]],
            [
                'object' => 'block',
                'id' => 'managed-block',
                'type' => 'paragraph',
                'paragraph' => [
                    'rich_text' => [[
                        'plain_text' => '[AIOS managed roadmap content]'."\n".'Old managed body',
                    ]],
                ],
            ],
        ], 'has_more' => false], 200, ['x-request-id' => 'req-children']),
        'https://api.notion.com/v1/blocks/managed-block' => Http::response(['object' => 'block', 'id' => 'managed-block'], 200, ['x-request-id' => 'req-block']),
    ]);

    app(NotionPublicationClient::class)->updatePage(
        IntegrationCredentialSecret::from('secret_notion_abcdefghijklmnopqrstuvwxyz'),
        'page-1',
        ['Ticket ID' => ['rich_text' => [['type' => 'text', 'text' => ['content' => 'ticket-1']]]]],
        str_repeat('body ', 500),
    );

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $request->method() === 'PATCH'
            && $request->url() === 'https://api.notion.com/v1/pages/page-1'
            && ! array_key_exists('erase_content', $data)
            && ! array_key_exists('children', $data);
    });
    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $request->method() === 'PATCH'
            && $request->url() === 'https://api.notion.com/v1/blocks/managed-block'
            && count($data['paragraph']['rich_text'] ?? []) === 2
            && str_starts_with((string) ($data['paragraph']['rich_text'][0]['text']['content'] ?? ''), '[AIOS managed roadmap content]'."\n")
            && $request->hasHeader('Notion-Version', '2026-03-11');
    });
});

test('it returns only the managed body projection when a page also contains external QA blocks', function (): void {
    Http::fake([
        'https://api.notion.com/v1/blocks/page-1/children*' => Http::response(['object' => 'list', 'results' => [
            ['object' => 'block', 'id' => 'qa-block', 'type' => 'paragraph', 'paragraph' => ['rich_text' => [['plain_text' => 'Existing QA evidence']]]],
            ['object' => 'block', 'id' => 'managed-block', 'type' => 'paragraph', 'paragraph' => ['rich_text' => [['plain_text' => '[AIOS managed roadmap content]'."\n".'## Objective'."\n".'- Canonical objective']]]],
        ], 'has_more' => false], 200, ['x-request-id' => 'req-body']),
    ]);

    $body = app(NotionPublicationClient::class)->retrievePageBody(
        IntegrationCredentialSecret::from('secret_notion_abcdefghijklmnopqrstuvwxyz'),
        'page-1',
    );

    expect($body)->toBe("## Objective\n- Canonical objective");
});

test('it retries a short Notion rate limit and returns the subsequent response', function (): void {
    config()->set('services.notion.retry_attempts', 2);
    Http::fake([
        'https://api.notion.com/v1/data_sources/source-1' => Http::sequence()
            ->push(['object' => 'error'], 429, ['Retry-After' => '0', 'x-request-id' => 'req-rate-limit'])
            ->push(['object' => 'data_source', 'id' => 'source-1', 'name' => 'Tickets', 'properties' => []], 200, ['x-request-id' => 'req-retried']),
    ]);

    $source = app(NotionPublicationClient::class)->retrieveDataSource(
        IntegrationCredentialSecret::from('secret_notion_abcdefghijklmnopqrstuvwxyz'),
        'source-1',
    );

    expect($source->providerRequestId)->toBe('req-retried');
    Http::assertSentCount(2);
});

test('it classifies provider failures without exposing the credential', function (int $status, string $category, bool $retryable): void {
    config()->set('services.notion.retry_attempts', 1);
    Http::fake([
        'https://api.notion.com/v1/data_sources/source-1' => Http::response(['object' => 'error', 'message' => 'provider detail'], $status, ['x-request-id' => 'req-provider-error']),
    ]);

    try {
        app(NotionPublicationClient::class)->retrieveDataSource(
            IntegrationCredentialSecret::from('secret_notion_abcdefghijklmnopqrstuvwxyz'),
            'source-1',
        );
        test()->fail('The provider request should fail.');
    } catch (NotionPublicationException $exception) {
        expect($exception->category)->toBe($category)
            ->and($exception->retryable)->toBe($retryable)
            ->and($exception->providerRequestId)->toBe('req-provider-error')
            ->and($exception->getMessage())->not->toContain('secret_notion_abcdefghijklmnopqrstuvwxyz')
            ->and($exception->getMessage())->not->toContain('provider detail');
    }
})->with([
    'invalid token' => [401, 'invalid_token', false],
    'forbidden' => [403, 'forbidden', false],
    'not found' => [404, 'not_found', false],
    'conflict' => [409, 'conflict', true],
    'rate limited' => [429, 'rate_limited', true],
    'server error' => [503, 'provider_unavailable', true],
]);

test('it rejects malformed Notion list pagination before application code consumes it', function (): void {
    Http::fake([
        'https://api.notion.com/v1/data_sources/source-1/query' => Http::response(['object' => 'list', 'results' => [], 'has_more' => true, 'next_cursor' => null], 200, ['x-request-id' => 'req-malformed']),
    ]);

    try {
        app(NotionPublicationClient::class)->findPagesByTicketKey(
            IntegrationCredentialSecret::from('secret_notion_abcdefghijklmnopqrstuvwxyz'),
            'source-1',
            'ticket-1',
        );
        test()->fail('The malformed page result should be rejected.');
    } catch (NotionPublicationException $exception) {
        expect($exception->category)->toBe('malformed_response')
            ->and($exception->retryable)->toBeFalse()
            ->and($exception->providerRequestId)->toBe('req-malformed');
    }
});
