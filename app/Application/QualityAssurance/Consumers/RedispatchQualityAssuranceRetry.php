<?php

declare(strict_types=1);

namespace App\Application\QualityAssurance\Consumers;

use App\Application\Events\Contracts\DomainEventConsumer;
use App\Application\Events\Data\StoredDomainEvent;
use App\Domain\Executions\ExecutionCapability;
use App\Domain\Executions\ExecutionStatus;
use App\Jobs\ProcessQualityAssuranceExecutionJob;
use App\Models\Execution;
use App\Models\QaAssessment;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use UnexpectedValueException;

/**
 * Redispatches a QA job after the domain retry delay has been released.
 */
final class RedispatchQualityAssuranceRetry implements DomainEventConsumer
{
    /**
     * Return the stable deduplication identity for retry events.
     */
    public function consumerName(): string
    {
        return 'quality-assurance.redispatch-released-retry';
    }

    /**
     * Subscribe to the shared execution retry-release event.
     *
     * @return list<string>
     */
    public function subscribedEventNames(): array
    {
        return ['execution.retry_released'];
    }

    /**
     * Dispatch only queued quality-assurance simulation executions.
     */
    public function handle(StoredDomainEvent $event): void
    {
        if (
            $event->schemaVersion !== 1
            || $event->projectId === null
        ) {
            throw new UnexpectedValueException(
                'Retry release event contract is unsupported.',
            );
        }

        $executionId =
            $event->payload()['execution_id'] ?? null;

        if (
            ! is_string($executionId)
            || ! Str::isUlid($executionId)
        ) {
            throw new UnexpectedValueException(
                'Retry release event payload is invalid.',
            );
        }

        $execution = Execution::query()
            ->forProject($event->projectId)
            ->whereKey($executionId)
            ->forCapability(
                ExecutionCapability::QualityAssuranceReview,
            )
            ->firstOrFail();

        if (
            $execution->status !== ExecutionStatus::Queued
            || $execution->cancel_requested_at !== null
        ) {
            return;
        }

        $assessment = QaAssessment::query()
            ->forProject($event->projectId)
            ->where('review_execution_id', $execution->id)
            ->where(
                'status',
                QaAssessment::STATUS_RETRY_SCHEDULED,
            )
            ->firstOrFail();

        Bus::dispatch(
            (new ProcessQualityAssuranceExecutionJob(
                $assessment->id,
            ))->afterCommit(),
        );
    }
}
