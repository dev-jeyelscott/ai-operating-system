<?php

declare(strict_types=1);

namespace App\Application\Projects;

use App\Application\Integrations\Data\NotionProjectConfigurationSnapshot;
use App\Domain\Integrations\IntegrationProvider;
use App\Domain\Integrations\NotionConnectionStatus;
use App\Domain\Projects\Configuration\Exceptions\UnsupportedProjectConfigurationSchemaVersion;
use App\Domain\Projects\Configuration\ProjectConfigurationSchema;

/**
 * Converts historical project configuration snapshots to the current schema.
 *
 * Persisted immutable rows are never changed.
 */
final class ProjectConfigurationSnapshotUpcaster
{
    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function toCurrent(array $snapshot): array
    {
        $version = (int) ($snapshot['schema_version'] ?? 0);

        return match ($version) {
            ProjectConfigurationSchema::VERSION_1 => $this->versionOneToVersionTwo($snapshot),

            ProjectConfigurationSchema::VERSION_2 => $snapshot,

            default => throw UnsupportedProjectConfigurationSchemaVersion::forVersion(
                $version,
            ),
        };
    }

    /**
     * Convert either the original integration-free v1 snapshot or the partial
     * flat integration shape introduced before AIOS-174.
     *
     * Unknown credential provenance remains null rather than being invented.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function versionOneToVersionTwo(
        array $snapshot,
    ): array {
        $legacyNotion = $snapshot['integrations']['notion'] ?? null;

        $workspaceId = is_array($legacyNotion)
            ? ($legacyNotion['workspace_id'] ?? null)
            : null;

        $workspaceName = is_array($legacyNotion)
            ? ($legacyNotion['workspace_name'] ?? null)
            : null;

        $databaseId = is_array($legacyNotion)
            ? ($legacyNotion['database_id'] ?? null)
            : null;

        $databaseName = is_array($legacyNotion)
            ? ($legacyNotion['database_name'] ?? null)
            : null;

        $dataSourceId = is_array($legacyNotion)
            ? ($legacyNotion['data_source_id'] ?? null)
            : null;

        $dataSourceName = is_array($legacyNotion)
            ? ($legacyNotion['data_source_name'] ?? null)
            : null;

        $hasLegacyTarget =
            is_string($workspaceId)
            && $workspaceId !== ''
            && is_string($databaseId)
            && $databaseId !== ''
            && is_string($dataSourceId)
            && $dataSourceId !== '';

        $snapshot['schema_version'] =
            ProjectConfigurationSchema::VERSION_2;

        $snapshot['integrations'] = [
            'notion' => [
                'provider' => IntegrationProvider::Notion->value,

                'connection_status' => $hasLegacyTarget
                    ? NotionConnectionStatus::Connected->value
                    : NotionProjectConfigurationSnapshot::UNCONFIGURED,

                'workspace' => [
                    'id' => $workspaceId,
                    'name' => $workspaceName,
                ],

                'database' => [
                    'id' => $databaseId,
                    'name' => $databaseName,
                ],

                'data_source' => [
                    'id' => $dataSourceId,
                    'name' => $dataSourceName,
                ],

                /*
                 * Version-one snapshots did not preserve this provenance.
                 * Null means historically unknown, not unconfigured.
                 */
                'credential' => [
                    'configured' => null,
                    'verified_version' => null,
                ],

                'verified_at' => null,
            ],
        ];

        return $snapshot;
    }
}
