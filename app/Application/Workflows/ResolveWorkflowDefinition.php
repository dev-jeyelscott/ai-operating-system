<?php

declare(strict_types=1);

namespace App\Application\Workflows;

use App\Models\WorkflowDefinition;
use InvalidArgumentException;

/**
 * Resolves workflow definitions without mutating version history.
 */
final class ResolveWorkflowDefinition
{
    /**
     * Resolve one exact immutable definition version.
     */
    public function exact(
        string $definitionKey,
        int $version,
    ): WorkflowDefinition {
        if ($version < 1) {
            throw new InvalidArgumentException(
                'The workflow-definition version must be at least one.',
            );
        }

        return WorkflowDefinition::query()
            ->where(
                'definition_key',
                $this->normalizeKey($definitionKey),
            )
            ->where('version', $version)
            ->firstOrFail();
    }

    /**
     * Resolve the latest published version for new workflow instances only.
     */
    public function latest(string $definitionKey): WorkflowDefinition
    {
        return WorkflowDefinition::query()
            ->where(
                'definition_key',
                $this->normalizeKey($definitionKey),
            )
            ->orderByDesc('version')
            ->firstOrFail();
    }

    /**
     * Normalize the stable lookup key.
     */
    private function normalizeKey(string $definitionKey): string
    {
        $normalized = trim($definitionKey);

        if ($normalized === '') {
            throw new InvalidArgumentException(
                'The workflow-definition key is required.',
            );
        }

        return $normalized;
    }
}
