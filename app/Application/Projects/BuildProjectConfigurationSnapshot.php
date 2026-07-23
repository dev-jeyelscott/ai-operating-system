<?php

declare(strict_types=1);

namespace App\Application\Projects;

use App\Application\Integrations\Contracts\ProjectIntegrationSnapshotReader;
use App\Application\Projects\Data\ProjectConfigurationSnapshot;
use App\Models\ProjectConfiguration;
use InvalidArgumentException;

/**
 * Composes the complete credential-free project configuration snapshot.
 */
final readonly class BuildProjectConfigurationSnapshot
{
    public function __construct(
        private ProjectIntegrationSnapshotReader $integrations,
    ) {}

    public function handle(
        int $organizationId,
        ProjectConfiguration $configuration,
    ): ProjectConfigurationSnapshot {
        if ($organizationId < 1) {
            throw new InvalidArgumentException(
                'The organization identifier must be positive.',
            );
        }

        if ($configuration->project_id < 1) {
            throw new InvalidArgumentException(
                'The project identifier must be positive.',
            );
        }

        return new ProjectConfigurationSnapshot(
            configuration: $configuration->toVersionedArray(),

            integrations: $this->integrations->forProject(
                organizationId: $organizationId,
                projectId: $configuration->project_id,
            ),
        );
    }
}
