<?php

declare(strict_types=1);

namespace App\Application\Integrations\Contracts;

use App\Application\Integrations\NotionConnectionTestResult;
use App\Domain\Integrations\IntegrationCredentialSecret;
use App\Domain\Integrations\NotionDatabaseId;

/**
 * Tests read-only access to a Notion workspace and database.
 */
interface NotionConnectionGateway
{
    /**
     * Validate the credential, workspace, and configured database.
     */
    public function test(
        IntegrationCredentialSecret $credential,
        NotionDatabaseId $databaseId,
        ?string $expectedWorkspaceId,
        ?string $selectedDataSourceId = null,
    ): NotionConnectionTestResult;
}
