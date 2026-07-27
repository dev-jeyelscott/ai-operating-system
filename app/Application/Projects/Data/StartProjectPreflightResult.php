<?php

declare(strict_types=1);

namespace App\Application\Projects\Data;

use InvalidArgumentException;

/**
 * Immutable, credential-free read model for the StartProject preflight query.
 */
final readonly class StartProjectPreflightResult
{
    /**
     * Create one complete preflight result without exposing provider secrets.
     *
     * @param  array<string, mixed>  $configuration
     * @param  array<string, mixed>  $documents
     * @param  array<string, mixed>  $integration
     * @param  array<string, mixed>  $cost
     * @param  array<string, mixed>  $approvalGates
     * @param  array<string, mixed>  $execution
     * @param  array<string, mixed>  $contextSnapshot
     * @param  list<array{
     *     key: string,
     *     category: string,
     *     message: string,
     *     remediation: string
     * }>  $blockers
     */
    public function __construct(
        public int $projectId,
        public string $projectStatus,
        public bool $canStart,
        public array $configuration,
        public array $documents,
        public array $integration,
        public array $cost,
        public array $approvalGates,
        public array $execution,
        public array $contextSnapshot,
        public array $blockers,
    ) {
        if ($projectId < 1) {
            throw new InvalidArgumentException(
                'The project identifier must be positive.',
            );
        }

        if (trim($projectStatus) === '') {
            throw new InvalidArgumentException(
                'The project status must not be empty.',
            );
        }
    }

    /**
     * Serialize the preflight result into the stable application response shape.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'project_id' => $this->projectId,
            'project_status' => $this->projectStatus,
            'can_start' => $this->canStart,
            'configuration' => $this->configuration,
            'documents' => $this->documents,
            'integration' => $this->integration,
            'cost' => $this->cost,
            'approval_gates' => $this->approvalGates,
            'execution' => $this->execution,
            'context_snapshot' => $this->contextSnapshot,
            'blockers' => $this->blockers,
        ];
    }
}
