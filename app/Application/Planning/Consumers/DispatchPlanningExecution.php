<?php

declare(strict_types=1);

namespace App\Application\Planning\Consumers;

use App\Application\Events\Contracts\DomainEventConsumer;
use App\Application\Events\Data\StoredDomainEvent;
use App\Domain\Executions\ExecutionCapability;
use App\Domain\Executions\ExecutionStatus;
use App\Jobs\ProcessPlanningExecutionJob;
use App\Models\Execution;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use UnexpectedValueException;

final class DispatchPlanningExecution implements DomainEventConsumer
{
    public function consumerName(): string
    {
        return 'planning.dispatch-project-start';
    }

    /** @return list<string> */
    public function subscribedEventNames(): array
    {
        return ['project.start_requested', 'roadmap.regeneration_requested'];
    }

    public function handle(StoredDomainEvent $event): void
    {
        if ($event->schemaVersion !== 1 || $event->projectId === null) {
            throw new UnexpectedValueException('The project start event contract is unsupported.');
        }

        $payload = $event->payload();
        $executionId = $payload['execution_id'] ?? null;
        $projectId = $payload['project_id'] ?? null;
        $snapshotId = $payload['project_context_snapshot_id'] ?? null;
        $capability = $payload['capability'] ?? null;
        $feedbackFingerprint = $payload['feedback_fingerprint'] ?? null;

        if (
            ! is_string($capability)
            || ! ExecutionCapability::PlanningGenerate->accepts(
                $capability,
            )
        ) {
            throw new UnexpectedValueException(
                'The project start capability is invalid.',
            );
        }

        $execution = Execution::query()
            ->forProject($projectId)
            ->whereKey($executionId)
            ->where(
                'project_context_snapshot_id',
                $snapshotId,
            )
            ->forCapability(
                ExecutionCapability::PlanningGenerate,
            )
            ->firstOrFail();

        if ($execution->status !== ExecutionStatus::Queued) {
            return;
        }

        Bus::dispatch((new ProcessPlanningExecutionJob(
            executionId: $execution->id,
            feedbackFingerprint: is_string($feedbackFingerprint) ? $feedbackFingerprint : null,
        ))->afterCommit());
    }
}
