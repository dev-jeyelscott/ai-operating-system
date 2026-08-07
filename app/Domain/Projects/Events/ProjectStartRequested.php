<?php

declare(strict_types=1);

namespace App\Domain\Projects\Events;

use App\Domain\Events\DomainEvent;

/**
 * Reports the accepted request to begin Layer 1 project planning.
 */
final readonly class ProjectStartRequested implements DomainEvent
{
    /**
     * Store the immutable StartProject event payload.
     */
    public function __construct(
        public int $projectId,
        public int $projectContextSnapshotId,
        public int $workflowInstanceId,
        public int $workflowDefinitionId,
        public int $workflowDefinitionVersion,
        public string $executionId,
        public string $capability,
    ) {}

    /**
     * Return the stable event contract name.
     */
    public static function eventName(): string
    {
        return 'project.start_requested';
    }

    /**
     * Return the payload schema version.
     */
    public static function schemaVersion(): int
    {
        return 1;
    }

    /**
     * Return the sanitized business payload.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'project_id' => $this->projectId,
            'project_context_snapshot_id' => $this->projectContextSnapshotId,
            'workflow_instance_id' => $this->workflowInstanceId,
            'workflow_definition_id' => $this->workflowDefinitionId,
            'workflow_definition_version' => $this->workflowDefinitionVersion,
            'execution_id' => $this->executionId,
            'capability' => $this->capability,
        ];
    }
}
