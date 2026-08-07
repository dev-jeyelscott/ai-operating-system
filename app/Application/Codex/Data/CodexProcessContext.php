<?php

declare(strict_types=1);

namespace App\Application\Codex\Data;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Carries immutable Laravel-owned lineage and runtime policy for one Codex
 * App Server process.
 */
final readonly class CodexProcessContext
{
    /**
     * Create one validated process context.
     */
    public function __construct(
        public string $providerSessionId,
        public int $organizationId,
        public int $projectId,
        public string $executionId,
        public int $executionAttemptId,
        public string $workspacePath,
        public string $codexHomePath,
        public string $modelIdentifier,
        public string $sandboxProfile,
        public string $networkPolicy,
        public int $timeoutSeconds,
    ) {
        if (! Str::isUlid($this->providerSessionId)) {
            throw new InvalidArgumentException(
                'Provider session identifier must be a ULID.',
            );
        }

        if (! Str::isUlid($this->executionId)) {
            throw new InvalidArgumentException(
                'Execution identifier must be a ULID.',
            );
        }

        if (
            $this->organizationId < 1
            || $this->projectId < 1
            || $this->executionAttemptId < 1
        ) {
            throw new InvalidArgumentException(
                'Codex execution lineage identifiers must be positive.',
            );
        }

        $this->assertAbsolutePath(
            $this->workspacePath,
            'workspace',
        );

        $this->assertAbsolutePath(
            $this->codexHomePath,
            'Codex home',
        );

        if (trim($this->modelIdentifier) === '') {
            throw new InvalidArgumentException(
                'Codex model identifier is required.',
            );
        }

        if (! in_array(
            $this->sandboxProfile,
            ['read-only', 'workspace-write'],
            true,
        )) {
            throw new InvalidArgumentException(
                'Unsupported Codex sandbox profile.',
            );
        }

        if (trim($this->networkPolicy) === '') {
            throw new InvalidArgumentException(
                'Codex network policy is required.',
            );
        }

        if (
            $this->timeoutSeconds < 30
            || $this->timeoutSeconds > 3600
        ) {
            throw new InvalidArgumentException(
                'Codex process timeout must be between 30 and 3600 seconds.',
            );
        }
    }

    /**
     * Reject relative paths and simple parent-directory traversal.
     */
    private function assertAbsolutePath(
        string $path,
        string $label,
    ): void {
        if (
            ! str_starts_with($path, '/')
            || str_contains($path, '/../')
            || str_ends_with($path, '/..')
        ) {
            throw new InvalidArgumentException(
                "Codex {$label} path must be an absolute non-traversing path.",
            );
        }
    }
}
