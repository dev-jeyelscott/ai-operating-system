<?php

declare(strict_types=1);

namespace App\Application\Development\Consumers;

use App\Application\Events\Contracts\DomainEventConsumer;
use App\Application\Events\Data\StoredDomainEvent;
use App\Domain\Executions\ExecutionCapability;
use App\Domain\Executions\ExecutionStatus;
use App\Jobs\ProcessDevelopmentExecutionJob;
use App\Models\Execution;
use App\Models\RoadmapTask;
use App\Models\TicketExecutionLease;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

final class DispatchDevelopmentExecution implements DomainEventConsumer
{
    public function consumerName(): string
    {
        return 'development.dispatch-ticket-lease';
    }

    /** @return list<string> */
    public function subscribedEventNames(): array
    {
        return ['ticket.lease_acquired'];
    }

    public function handle(StoredDomainEvent $event): void
    {
        if ($event->schemaVersion !== 1 || $event->projectId === null) {
            throw new \UnexpectedValueException('Ticket lease event contract is unsupported.');
        }
        $payload = $event->payload();
        $executionId = $payload['execution_id'] ?? null;
        $leaseId = $payload['lease_id'] ?? null;
        $ticketId = $payload['roadmap_task_id'] ?? null;

        if (! is_string($executionId) || ! Str::isUlid($executionId) || ! is_string($leaseId) || ! Str::isUlid($leaseId) || ! is_int($ticketId)) {
            throw new \UnexpectedValueException('Ticket lease event payload is invalid.');
        }

        $execution = Execution::query()->forProject($event->projectId)
            ->whereKey($executionId)->forCapability(
                ExecutionCapability::DevelopmentExecute,
            )->firstOrFail();
        TicketExecutionLease::query()->where('project_id', $event->projectId)->where('execution_id', $execution->id)->where('roadmap_task_id', $ticketId)->whereKey($leaseId)->active()->firstOrFail();
        RoadmapTask::query()->whereHas('roadmap', fn($query) => $query->where('project_id', $event->projectId))->whereKey($ticketId)->firstOrFail();

        if ($execution->status !== ExecutionStatus::Queued || $execution->cancel_requested_at !== null) {
            return;
        }

        Bus::dispatch((new ProcessDevelopmentExecutionJob($execution->id))->afterCommit());
    }
}
