<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Application\Integrations\Contracts\NotionPublicationClient;
use App\Application\Integrations\Data\NotionDataSource;
use App\Application\Integrations\Data\NotionPage;
use App\Application\Integrations\NotionPublicationException;
use App\Domain\Integrations\IntegrationCredentialSecret;

/**
 * Offline Notion publication fixture for workflow tests.
 *
 * It intentionally models only the typed publication boundary, records calls,
 * and never inspects credentials or makes an HTTP request.
 */
final class InMemoryNotionPublicationClient implements NotionPublicationClient
{
    /** @var array<string, NotionPage> */
    private array $pages = [];

    /** @var array<string, string> */
    private array $bodies = [];

    /** @var list<array{operation:string, page_id?:string, data_source_id?:string, ticket_key?:string}> */
    public array $calls = [];

    /** @var array<string, list<NotionPublicationException>> */
    private array $failures = [];

    private int $nextPageNumber = 1;

    public function __construct(private readonly NotionDataSource $dataSource) {}

    public function seedPage(NotionPage $page, string $body): void
    {
        $this->pages[$page->id] = $page;
        $this->bodies[$page->id] = $body;
    }

    public function failNext(string $operation, NotionPublicationException $exception): void
    {
        $this->failures[$operation] ??= [];
        $this->failures[$operation][] = $exception;
    }

    public function retrieveDataSource(IntegrationCredentialSecret $credential, string $dataSourceId): NotionDataSource
    {
        $this->calls[] = ['operation' => 'retrieve_data_source', 'data_source_id' => $dataSourceId];
        $this->throwNextFailure('retrieve_data_source');

        if ($dataSourceId !== $this->dataSource->id) {
            throw new NotionPublicationException('not_found', false, 'fixture-source-not-found');
        }

        return $this->dataSource;
    }

    public function findPagesByTicketKey(IntegrationCredentialSecret $credential, string $dataSourceId, string $ticketKey): array
    {
        $this->calls[] = ['operation' => 'find_by_ticket_key', 'data_source_id' => $dataSourceId, 'ticket_key' => $ticketKey];
        $this->throwNextFailure('find_by_ticket_key');

        return array_values(array_filter(
            $this->pages,
            fn (NotionPage $page): bool => $page->dataSourceId === $dataSourceId && $this->ticketKey($page) === $ticketKey,
        ));
    }

    public function createPage(IntegrationCredentialSecret $credential, string $dataSourceId, array $properties, string $body): NotionPage
    {
        $this->calls[] = ['operation' => 'create_page', 'data_source_id' => $dataSourceId];
        $this->throwNextFailure('create_page');
        $pageId = 'fixture-page-'.($this->nextPageNumber++);
        $page = new NotionPage($pageId, $dataSourceId, 'https://www.notion.so/'.$pageId, $properties, 'fixture-create-'.$pageId);
        $this->pages[$pageId] = $page;
        $this->bodies[$pageId] = $body;

        return $page;
    }

    public function updatePage(IntegrationCredentialSecret $credential, string $pageId, array $properties, string $body): NotionPage
    {
        $this->calls[] = ['operation' => 'update_page', 'page_id' => $pageId];
        $this->throwNextFailure('update_page');
        $existing = $this->retrievePage($credential, $pageId);
        $page = new NotionPage($pageId, $existing->dataSourceId, $existing->url, $properties, 'fixture-update-'.$pageId);
        $this->pages[$pageId] = $page;
        $this->bodies[$pageId] = $body;

        return $page;
    }

    public function retrievePage(IntegrationCredentialSecret $credential, string $pageId): NotionPage
    {
        $this->calls[] = ['operation' => 'retrieve_page', 'page_id' => $pageId];
        $this->throwNextFailure('retrieve_page');

        return $this->pages[$pageId] ?? throw new NotionPublicationException('not_found', false, 'fixture-page-not-found');
    }

    public function retrievePageBody(IntegrationCredentialSecret $credential, string $pageId): string
    {
        $this->calls[] = ['operation' => 'retrieve_page_body', 'page_id' => $pageId];
        $this->throwNextFailure('retrieve_page_body');

        if (! array_key_exists($pageId, $this->bodies)) {
            throw new NotionPublicationException('not_found', false, 'fixture-page-not-found');
        }

        return $this->bodies[$pageId];
    }

    private function ticketKey(NotionPage $page): ?string
    {
        $items = $page->properties['Ticket ID']['rich_text'] ?? [];
        if (! is_array($items)) {
            return null;
        }

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $key = $item['plain_text'] ?? $item['text']['content'] ?? null;
            if (is_string($key)) {
                return $key;
            }
        }

        return null;
    }

    private function throwNextFailure(string $operation): void
    {
        if (! isset($this->failures[$operation])) {
            return;
        }

        $exception = array_shift($this->failures[$operation]);
        if ($this->failures[$operation] === []) {
            unset($this->failures[$operation]);
        }
        if ($exception instanceof NotionPublicationException) {
            throw $exception;
        }
    }
}
