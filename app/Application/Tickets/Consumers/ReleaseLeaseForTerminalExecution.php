<?php

declare(strict_types=1);

namespace App\Application\Tickets\Consumers;

use App\Application\Events\Contracts\DomainEventConsumer;
use App\Application\Events\Data\StoredDomainEvent;
use App\Application\Tickets\TicketLeaseManager;
use App\Domain\Executions\ExecutionStatus;
use App\Domain\Tickets\TicketLeaseReleaseReason;
use App\Models\Execution;
use App\Models\TicketExecutionLease;

final readonly class ReleaseLeaseForTerminalExecution implements DomainEventConsumer
{
    public function __construct(private TicketLeaseManager $leases) {}

    public function consumerName(): string
    {
        return 'tickets.release-lease-for-terminal-execution';
    }

    /** @return list<string> */
    public function subscribedEventNames(): array
    {
        return ['execution.attempt.completed', 'execution.failed', 'execution.cancelled'];
    }

    public function handle(StoredDomainEvent $event): void
    {
        if ($event->schemaVersion !== 1 || $event->projectId === null) {
            throw new \UnexpectedValueException('Terminal execution event contract is unsupported.');
        }

        $executionId = $event->payload()['execution_id'] ?? null;

        if (! is_string($executionId)) {
            throw new \UnexpectedValueException('Terminal execution event payload is invalid.');
        }

        $execution = Execution::query()->forProject($event->projectId)->whereKey($executionId)->firstOrFail();
        $reason = match ($execution->status) {
            ExecutionStatus::Completed => TicketLeaseReleaseReason::Completion,
            ExecutionStatus::Failed => TicketLeaseReleaseReason::TerminalFailure,
            ExecutionStatus::Cancelled => TicketLeaseReleaseReason::Cancellation,
            default => null,
        };

        if ($reason === null) {
            return;
        }

        $lease = TicketExecutionLease::query()->where('execution_id', $execution->id)->first();

        if ($lease === null) {
            return;
        }

        $this->leases->releaseForExecution(
            organizationId: $event->organizationId,
            projectId: $event->projectId,
            leaseId: $lease->id,
            executionId: $execution->id,
            owner: $lease->owner,
            reason: $reason,
        );
    }
}
