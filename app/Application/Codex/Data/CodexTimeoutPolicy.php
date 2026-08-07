<?php

declare(strict_types=1);

namespace App\Application\Codex\Data;

use App\Domain\Codex\CodexExecutionPhase;
use App\Models\Execution;
use InvalidArgumentException;

/**
 * Captures the immutable timeout policy used by one Codex attempt.
 */
final readonly class CodexTimeoutPolicy
{
    /**
     * Create a validated immutable timeout policy.
     */
    public function __construct(
        public int $startupTimeoutSeconds,
        public int $idleTimeoutSeconds,
        public int $turnTimeoutSeconds,
        public int $approvalWaitTimeoutSeconds,
        public int $validationTimeoutSeconds,
        public int $shutdownTimeoutSeconds,
        public int $cancellationGraceSeconds,
        public int $heartbeatIntervalSeconds,
        public int $staleHeartbeatSeconds,
    ) {
        foreach ($this->toArray() as $name => $seconds) {
            if ($seconds < 1) {
                throw new InvalidArgumentException(
                    "Codex timeout value {$name} must be greater than zero.",
                );
            }
        }

        if ($this->staleHeartbeatSeconds <= $this->heartbeatIntervalSeconds) {
            throw new InvalidArgumentException(
                'The stale Codex heartbeat threshold must exceed the heartbeat interval.',
            );
        }
    }

    /**
     * Resolve a timeout policy from immutable execution policy plus system caps.
     */
    public static function fromExecution(Execution $execution): self
    {
        $executionTimeout = max(
            1,
            $execution->timeout_seconds,
        );

        return new self(
            startupTimeoutSeconds: self::configured(
                'codex-app-server.startup_timeout_seconds',
                30,
            ),
            idleTimeoutSeconds: min(
                $executionTimeout,
                self::configured(
                    'codex-app-server.idle_timeout_seconds',
                    60,
                ),
            ),
            turnTimeoutSeconds: min(
                $executionTimeout,
                self::configured(
                    'codex-app-server.turn_timeout_seconds',
                    900,
                ),
            ),
            approvalWaitTimeoutSeconds: min(
                $executionTimeout,
                self::configured(
                    'codex-app-server.approval_wait_timeout_seconds',
                    900,
                ),
            ),
            validationTimeoutSeconds: min(
                $executionTimeout,
                self::configured(
                    'codex-app-server.validation_timeout_seconds',
                    900,
                ),
            ),
            shutdownTimeoutSeconds: self::configured(
                'codex-app-server.shutdown_grace_seconds',
                10,
            ),
            cancellationGraceSeconds: self::configured(
                'codex-app-server.cancellation_grace_seconds',
                10,
            ),
            heartbeatIntervalSeconds: self::configured(
                'codex-app-server.persistence.heartbeat_interval_seconds',
                15,
            ),
            staleHeartbeatSeconds: self::configured(
                'codex-app-server.persistence.stale_heartbeat_seconds',
                45,
            ),
        );
    }

    /**
     * Reconstruct one previously persisted immutable timeout policy.
     *
     * @param  array<string, mixed>  $values
     */
    public static function fromArray(array $values): self
    {
        return new self(
            startupTimeoutSeconds: self::arrayInteger(
                $values,
                'startup_timeout_seconds',
            ),
            idleTimeoutSeconds: self::arrayInteger(
                $values,
                'idle_timeout_seconds',
            ),
            turnTimeoutSeconds: self::arrayInteger(
                $values,
                'turn_timeout_seconds',
            ),
            approvalWaitTimeoutSeconds: self::arrayInteger(
                $values,
                'approval_wait_timeout_seconds',
            ),
            validationTimeoutSeconds: self::arrayInteger(
                $values,
                'validation_timeout_seconds',
            ),
            shutdownTimeoutSeconds: self::arrayInteger(
                $values,
                'shutdown_timeout_seconds',
            ),
            cancellationGraceSeconds: self::arrayInteger(
                $values,
                'cancellation_grace_seconds',
            ),
            heartbeatIntervalSeconds: self::arrayInteger(
                $values,
                'heartbeat_interval_seconds',
            ),
            staleHeartbeatSeconds: self::arrayInteger(
                $values,
                'stale_heartbeat_seconds',
            ),
        );
    }

    /**
     * Return the timeout applicable to the requested lifecycle phase.
     */
    public function secondsFor(CodexExecutionPhase $phase): int
    {
        return match ($phase) {
            CodexExecutionPhase::Startup => $this->startupTimeoutSeconds,
            CodexExecutionPhase::Idle => $this->idleTimeoutSeconds,
            CodexExecutionPhase::Turn => $this->turnTimeoutSeconds,
            CodexExecutionPhase::ApprovalWait => $this->approvalWaitTimeoutSeconds,
            CodexExecutionPhase::Validation => $this->validationTimeoutSeconds,
            CodexExecutionPhase::Shutdown => $this->shutdownTimeoutSeconds,
        };
    }

    /**
     * Convert the timeout policy into durable provider-session metadata.
     *
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'startup_timeout_seconds' => $this->startupTimeoutSeconds,
            'idle_timeout_seconds' => $this->idleTimeoutSeconds,
            'turn_timeout_seconds' => $this->turnTimeoutSeconds,
            'approval_wait_timeout_seconds' => $this->approvalWaitTimeoutSeconds,
            'validation_timeout_seconds' => $this->validationTimeoutSeconds,
            'shutdown_timeout_seconds' => $this->shutdownTimeoutSeconds,
            'cancellation_grace_seconds' => $this->cancellationGraceSeconds,
            'heartbeat_interval_seconds' => $this->heartbeatIntervalSeconds,
            'stale_heartbeat_seconds' => $this->staleHeartbeatSeconds,
        ];
    }

    /**
     * Read one positive integer from application configuration.
     */
    private static function configured(
        string $key,
        int $fallback,
    ): int {
        $value = config($key, $fallback);

        if (! is_int($value) || $value < 1) {
            throw new InvalidArgumentException(
                "Codex configuration {$key} must be a positive integer.",
            );
        }

        return $value;
    }

    /**
     * Read one required positive integer from persisted policy.
     *
     * @param  array<string, mixed>  $values
     */
    private static function arrayInteger(
        array $values,
        string $key,
    ): int {
        $value = $values[$key] ?? null;

        if (! is_int($value) || $value < 1) {
            throw new InvalidArgumentException(
                "Persisted Codex timeout {$key} is invalid.",
            );
        }

        return $value;
    }
}
