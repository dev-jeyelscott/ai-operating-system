<?php

declare(strict_types=1);

namespace App\Application\Integrations;

use App\Models\Project;

/**
 * Tests and atomically persists one project's Notion connection.
 *
 * @phpstan-type IntegrationFingerprint array<string, scalar|null>
 */
final readonly class TestProjectNotionConnection
{
    // ...

    /**
     * Persist a successful Notion result and all related project state atomically.
     *
     * @param  IntegrationFingerprint  $observedIntegrationFingerprint
     */
    private function persistSuccessfulResult(
        int $actorUserId,
        int $organizationId,
        int $projectId,
        // Other existing parameters...
        array $observedIntegrationFingerprint,
        // Other existing parameters...
    ): NotionConnectionTestResult {
        // Existing implementation remains unchanged.
    }

    // ...

    /**
     * Persist a failed Notion result without advancing successful setup state.
     *
     * @param  IntegrationFingerprint  $observedIntegrationFingerprint
     */
    private function persistFailedResult(
        int $actorUserId,
        int $organizationId,
        int $projectId,
        // Other existing parameters...
        array $observedIntegrationFingerprint,
        // Other existing parameters...
    ): NotionConnectionTestResult {
        // Existing implementation remains unchanged.
    }
}
