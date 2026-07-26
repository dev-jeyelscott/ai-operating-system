<?php

declare(strict_types=1);

namespace App\Application\Executions\Data;

use App\Domain\Executions\ExecutionStatus;
use Carbon\CarbonImmutable;

/**
 * Describes the authoritative result of handling an attempt failure.
 */
final readonly class RetryDecision
{
    /**
     * Create one retry or terminal-state decision.
     */
    public function __construct(
        public bool $stateChanged,
        public ExecutionStatus $executionStatus,
        public ?int $delaySeconds,
        public ?CarbonImmutable $nextAttemptAt,
    ) {}

    /**
     * Return a decision that schedules another attempt.
     */
    public static function scheduled(
        bool $stateChanged,
        int $delaySeconds,
        CarbonImmutable $nextAttemptAt,
    ): self {
        return new self(
            stateChanged: $stateChanged,
            executionStatus: ExecutionStatus::RetryScheduled,
            delaySeconds: $delaySeconds,
            nextAttemptAt: $nextAttemptAt,
        );
    }

    /**
     * Return a decision that reached a terminal execution state.
     */
    public static function terminal(
        bool $stateChanged,
        ExecutionStatus $status,
    ): self {
        return new self(
            stateChanged: $stateChanged,
            executionStatus: $status,
            delaySeconds: null,
            nextAttemptAt: null,
        );
    }
}
