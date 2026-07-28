<?php

declare(strict_types=1);

namespace App\Application\Integrations\Contracts;

use App\Application\Integrations\Data\NotionDataSource;
use App\Application\Integrations\Data\NotionPage;
use App\Domain\Integrations\IntegrationCredentialSecret;

/**
 * Typed, publication-only Notion boundary. Connection testing intentionally
 * remains isolated behind NotionConnectionGateway.
 */
interface NotionPublicationClient
{
    public function retrieveDataSource(
        IntegrationCredentialSecret $credential,
        string $dataSourceId,
    ): NotionDataSource;

    /** @return list<NotionPage> */
    public function findPagesByTicketKey(
        IntegrationCredentialSecret $credential,
        string $dataSourceId,
        string $ticketKey,
    ): array;

    /** @param array<string, mixed> $properties */
    public function createPage(
        IntegrationCredentialSecret $credential,
        string $dataSourceId,
        array $properties,
        string $body,
    ): NotionPage;

    /** @param array<string, mixed> $properties */
    public function updatePage(
        IntegrationCredentialSecret $credential,
        string $pageId,
        array $properties,
        string $body,
    ): NotionPage;

    public function retrievePage(
        IntegrationCredentialSecret $credential,
        string $pageId,
    ): NotionPage;

    /** Return normalized plain text from the page's top-level body blocks. */
    public function retrievePageBody(
        IntegrationCredentialSecret $credential,
        string $pageId,
    ): string;
}
