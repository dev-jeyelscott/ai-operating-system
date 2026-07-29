<?php

declare(strict_types=1);

namespace App\Application\Development\Consumers;

use App\Application\Events\Contracts\DomainEventConsumer;
use App\Application\Events\Data\StoredDomainEvent;
use App\Domain\Executions\ExecutionStatus;
use App\Jobs\ProcessDevelopmentExecutionJob;
use App\Models\Execution;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

final class RedispatchDevelopmentRetry implements DomainEventConsumer
{
    public function consumerName(): string
    {
        return 'development.redispatch-released-retry';
    }

    /** @return list<string> */
    public function subscribedEventNames(): array
    {
        return ['execution.retry_released'];
    }

    public function handle(StoredDomainEvent $event): void
    {
        if ($event->schemaVersion !== 1 || $event->projectId === null) {
            throw new \UnexpectedValueException('Retry release event contract is unsupported.');
        }
        $executionId = $event->payload()['execution_id'] ?? null;
        if (! is_string($executionId) || ! Str::isUlid($executionId)) {
            throw new \UnexpectedValueException('Retry release event payload is invalid.');
        }

        $execution = Execution::query()
            ->forProject($event->projectId)->whereKey($executionId)
            ->where('capability', 'development.simulation')->firstOrFail();

        if ($execution->status !== ExecutionStatus::Queued || $execution->cancel_requested_at !== null) {
            return;
        }

        $seed = (int) ($execution->attempts()->latest('attempt_number')->value('simulation_seed') ?? 1);
        Bus::dispatch((new ProcessDevelopmentExecutionJob($execution->id, seed: $seed))->afterCommit());
    }
}
