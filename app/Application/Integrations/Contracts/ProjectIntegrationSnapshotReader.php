<?php

declare(strict_types=1);

namespace App\Application\Integrations\Contracts;

use App\Application\Integrations\Data\ProjectIntegrationsSnapshot;

/**
 * Reads credential-free integration state for configuration snapshots.
 */
interface ProjectIntegrationSnapshotReader
{
    public function forProject(
        int $organizationId,
        int $projectId,
    ): ProjectIntegrationsSnapshot;
}
