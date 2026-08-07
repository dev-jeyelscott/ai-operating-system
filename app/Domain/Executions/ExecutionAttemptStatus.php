<?php

declare(strict_types=1);

namespace App\Domain\Executions;

/**
 * Defines the lifecycle state of one provider execution attempt.
 */
enum ExecutionAttemptStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case TimedOut = 'timed_out';
    case Cancelled = 'cancelled';

    /**
     * Determine whether this attempt has reached an immutable outcome.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed,
            self::Failed,
            self::TimedOut,
            self::Cancelled => true,
            default => false,
        };
    }
}
