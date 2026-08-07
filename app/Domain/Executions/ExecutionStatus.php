<?php

declare(strict_types=1);

namespace App\Domain\Executions;

/**
 * Defines the deterministic lifecycle state of an execution aggregate.
 */
enum ExecutionStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case WaitingForApproval = 'waiting_for_approval';
    case WaitingForEvidence = 'waiting_for_evidence';
    case Blocked = 'blocked';
    case RetryScheduled = 'retry_scheduled';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * Determine whether no further execution-state transition is expected.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed,
            self::Failed,
            self::Cancelled => true,
            default => false,
        };
    }
}
