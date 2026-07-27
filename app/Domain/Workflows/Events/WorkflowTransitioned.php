<?php

declare(strict_types=1);

namespace App\Domain\Workflows\Events;

use App\Domain\Events\DomainEvent;

/**
 * Reports one successfully committed workflow state transition.
 */
final readonly class WorkflowTransitioned implements DomainEvent
{
    /**
     * Store the immutable workflow transition event payload.
     */
    public function __construct(
        public int $workflowInstanceId,
        public int $workflowDefinitionId,
        public int $transitionSequence,
        public string $transitionName,
        public string $fromState,
        public string $toState,
        public ?string $guard,
    ) {}

    /**
     * Return the stable event contract name.
     */
    public static function eventName(): string
    {
        return 'workflow.transitioned';
    }

    /**
     * Return the payload schema version.
     */
    public static function schemaVersion(): int
    {
        return 1;
    }

    /**
     * Return the sanitized transition payload.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'workflow_instance_id' => $this->workflowInstanceId,
            'workflow_definition_id' => $this->workflowDefinitionId,
            'transition_sequence' => $this->transitionSequence,
            'transition_name' => $this->transitionName,
            'from_state' => $this->fromState,
            'to_state' => $this->toState,
            'guard' => $this->guard,
        ];
    }
}
