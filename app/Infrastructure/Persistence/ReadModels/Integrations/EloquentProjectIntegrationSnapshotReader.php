<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\ReadModels\Integrations;

use App\Application\Integrations\Contracts\ProjectIntegrationSnapshotReader;
use App\Application\Integrations\Data\NotionProjectConfigurationSnapshot;
use App\Application\Integrations\Data\ProjectIntegrationsSnapshot;
use App\Domain\Integrations\IntegrationProvider;
use App\Models\ProjectIntegration;
use App\Models\ProviderCredential;
use InvalidArgumentException;

/**
 * Builds credential-free integration snapshots through tenant-scoped reads.
 */
final readonly class EloquentProjectIntegrationSnapshotReader implements ProjectIntegrationSnapshotReader
{
    public function forProject(
        int $organizationId,
        int $projectId,
    ): ProjectIntegrationsSnapshot {
        if ($organizationId < 1) {
            throw new InvalidArgumentException(
                'The organization identifier must be positive.',
            );
        }

        if ($projectId < 1) {
            throw new InvalidArgumentException(
                'The project identifier must be positive.',
            );
        }

        $credentialConfigured = ProviderCredential::query()
            ->forOrganization($organizationId)
            ->forProject($projectId)
            ->where(
                'provider',
                IntegrationProvider::Notion->value,
            )
            ->exists();

        $integration = ProjectIntegration::query()
            ->forOrganization($organizationId)
            ->forProject($projectId)
            ->where(
                'provider',
                IntegrationProvider::Notion->value,
            )
            ->first();

        /*
         * A failed first attempt is not a verified target. Similarly, a project
         * with a stored credential but no successful provider result remains
         * explicitly unconfigured.
         */
        if (
            $integration === null
            || $integration->last_connected_at === null
            || $integration->workspace_id === null
            || $integration->database_id === null
            || $integration->data_source_id === null
            || $integration->verified_credential_version === null
        ) {
            return new ProjectIntegrationsSnapshot(
                notion: NotionProjectConfigurationSnapshot::unconfigured(
                    credentialConfigured: $credentialConfigured,
                ),
            );
        }

        return new ProjectIntegrationsSnapshot(
            notion: NotionProjectConfigurationSnapshot::connected(
                workspaceId: $integration->workspace_id,
                workspaceName: $integration->workspace_name,
                databaseId: $integration->database_id,
                databaseName: $integration->database_name,
                dataSourceId: $integration->data_source_id,
                dataSourceName: $integration->data_source_name,
                credentialConfigured: $credentialConfigured,
                verifiedCredentialVersion: $integration->verified_credential_version,
                verifiedAt: $integration->last_connected_at->toIso8601String(),
            ),
        );
    }
}
