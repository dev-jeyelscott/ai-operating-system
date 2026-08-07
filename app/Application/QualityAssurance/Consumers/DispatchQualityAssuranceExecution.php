<?php

declare(strict_types=1);

namespace App\Application\QualityAssurance\Consumers;

use App\Application\Events\Contracts\DomainEventConsumer;
use App\Application\Events\Data\StoredDomainEvent;
use App\Application\QualityAssurance\StartQualityAssuranceExecution;
use App\Domain\Executions\ExecutionStatus;
use App\Jobs\ProcessQualityAssuranceExecutionJob;
use Illuminate\Support\Str;
use UnexpectedValueException;

/**
 * Converts implementation completion into one queued Layer 3 execution.
 */
final readonly class DispatchQualityAssuranceExecution implements DomainEventConsumer
{
    public function __construct(
        private StartQualityAssuranceExecution $starter,
    ) {}

    /**
     * Return the stable deduplication identity for this consumer.
     */
    public function consumerName(): string
    {
        return 'quality-assurance.dispatch-implementation-completed';
    }

    /**
     * Subscribe only to successful Layer 2 completion.
     *
     * @return list<string>
     */
    public function subscribedEventNames(): array
    {
        return ['implementation.completed'];
    }

    /**
     * Validate event lineage, create the review execution, and queue processing.
     */
    public function handle(StoredDomainEvent $event): void
    {
        if (
            $event->schemaVersion !== 1
            || $event->projectId === null
        ) {
            throw new UnexpectedValueException(
                'Implementation completion event contract is unsupported.',
            );
        }

        $payload = $event->payload();

        $implementationExecutionId =
            $payload['execution_id'] ?? null;

        $implementationAttemptId =
            $payload['attempt_id'] ?? null;

        $roadmapTaskId =
            $payload['roadmap_task_id'] ?? null;

        if (
            ! is_string($implementationExecutionId)
            || ! Str::isUlid($implementationExecutionId)
            || ! is_int($implementationAttemptId)
            || $implementationAttemptId < 1
            || ! is_int($roadmapTaskId)
            || $roadmapTaskId < 1
        ) {
            throw new UnexpectedValueException(
                'Implementation completion event payload is invalid.',
            );
        }

        $assessment = $this->starter->handle(
            organizationId: $event->organizationId,
            projectId: $event->projectId,
            roadmapTaskId: $roadmapTaskId,
            implementationExecutionId: $implementationExecutionId,
            implementationAttemptId: $implementationAttemptId,
        );

        if (
            $assessment->reviewExecution->status
                !== ExecutionStatus::Queued
        ) {
            return;
        }

        ProcessQualityAssuranceExecutionJob::dispatch(
            $assessment->id,
        )->afterCommit();
    }
}
