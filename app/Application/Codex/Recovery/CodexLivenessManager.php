<?php

declare(strict_types=1);

namespace App\Application\Codex\Recovery;

use App\Application\Codex\Contracts\CodexRuntimeControl;
use App\Application\Codex\Persistence\ProviderSessionManager;
use App\Domain\Codex\CodexRuntimeState;
use App\Domain\Executions\ExecutionAttemptStatus;
use App\Models\ExecutionAttempt;
use App\Models\ProviderSession;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use LogicException;

/**
 * Evaluates suspicious Codex attempts without reclaiming leases or scheduling
 * retries before process ownership and cleanup are conclusively resolved.
 */
final readonly class CodexLivenessManager
{
    /**
     * Inject local runtime inspection and durable provider-session services.
     */
    public function __construct(
        private CodexRuntimeControl $runtime,
        private ProviderSessionManager $sessions,
    ) {}

    /**
     * Evaluate one running Codex attempt and flag it for safe recovery when a
     * cancellation, timeout, stale heartbeat, or provider-state divergence is
     * detected.
     */
    public function evaluate(
        int $executionAttemptId,
        ?CarbonImmutable $at = null,
    ): void {
        if ($executionAttemptId < 1) {
            throw new InvalidArgumentException(
                'Codex liveness evaluation requires a positive attempt identifier.',
            );
        }

        $evaluatedAt = $at ?? CarbonImmutable::now();

        /** @var ExecutionAttempt|null $attempt */
        $attempt = ExecutionAttempt::query()
            ->with('execution')
            ->find($executionAttemptId);

        if (
            $attempt === null
            || $attempt->execution_provider !== 'codex'
            || $attempt->status !== ExecutionAttemptStatus::Running
        ) {
            return;
        }

        /** @var ProviderSession|null $session */
        $session = ProviderSession::query()
            ->where(
                'execution_attempt_id',
                $attempt->id,
            )
            ->first();

        if ($session === null) {
            throw new LogicException(
                'Running Codex attempt is missing its provider session.',
            );
        }

        /*
         * Once recovery has already been persisted, this evaluator remains
         * idempotent. A later cleanup/reconciliation service owns continuation.
         */
        if ($session->recovery_required_at !== null) {
            return;
        }

        $reason = $this->recoveryReason(
            attempt: $attempt,
            session: $session,
            evaluatedAt: $evaluatedAt,
        );

        if ($reason === null) {
            return;
        }

        /*
         * A terminal provider session paired with a still-running application
         * attempt is already a divergence. Preserve it for reconciliation
         * instead of touching an OS process that should already be terminal.
         */
        if ($session->status->isTerminal()) {
            $this->sessions->markRecoveryRequired(
                session: $session,
                reason: $reason,
                at: $evaluatedAt,
            );

            return;
        }

        $runtimeState = $this->runtime->inspect(
            $session,
        );

        /*
         * A stale/expired/cancelled execution with the exact original process
         * still alive is treated as an owner-loss scenario. Termination remains
         * PID-identity guarded by CodexRuntimeControl.
         */
        if ($runtimeState === CodexRuntimeState::Running) {
            $runtimeState = $this->runtime->terminate(
                session: $session,
                graceSeconds: $this->cancellationGraceSeconds(),
            );
        }

        $this->sessions->markRecoveryRequired(
            session: $session,
            reason: $this->recoveryReasonForRuntimeState(
                reason: $reason,
                runtimeState: $runtimeState,
            ),
            at: $evaluatedAt,
        );
    }

    /**
     * Classify why a running Codex attempt requires recovery evaluation.
     */
    private function recoveryReason(
        ExecutionAttempt $attempt,
        ProviderSession $session,
        CarbonImmutable $evaluatedAt,
    ): ?string {
        if ($attempt->execution->cancel_requested_at !== null) {
            return 'codex.cancellation_pending';
        }

        if ($session->status->isTerminal()) {
            return 'codex.provider_session_terminal';
        }

        if (
            $session->phase_deadline_at !== null
            && $session->phase_deadline_at->lessThanOrEqualTo(
                $evaluatedAt,
            )
        ) {
            return 'codex.phase_timeout';
        }

        if (
            $attempt->deadline_at !== null
            && $attempt->deadline_at->lessThanOrEqualTo(
                $evaluatedAt,
            )
        ) {
            return 'codex.execution_timeout';
        }

        $heartbeat = $session->heartbeat_at
            ?? $attempt->heartbeat_at;

        if ($heartbeat === null) {
            return 'codex.heartbeat_missing';
        }

        if (
            $heartbeat->lessThanOrEqualTo(
                $evaluatedAt->subSeconds(
                    $this->staleHeartbeatSeconds(),
                ),
            )
        ) {
            return 'codex.heartbeat_stale';
        }

        return null;
    }

    /**
     * Preserve the triggering reason when the original process is gone.
     *
     * Ambiguous or failed runtime termination receives a more specific recovery
     * reason so operations never mistake uncertainty for a clean process exit.
     */
    private function recoveryReasonForRuntimeState(
        string $reason,
        CodexRuntimeState $runtimeState,
    ): string {
        return match ($runtimeState) {
            CodexRuntimeState::Exited => $reason,
            CodexRuntimeState::IdentityMismatch => 'codex.runtime_identity_mismatch',
            CodexRuntimeState::Unreachable => 'codex.runtime_unreachable',
            CodexRuntimeState::Running => 'codex.process_termination_failed',
        };
    }

    /**
     * Return the configured stale-heartbeat threshold.
     */
    private function staleHeartbeatSeconds(): int
    {
        $value = config(
            'codex-app-server.persistence.stale_heartbeat_seconds',
            45,
        );

        if (! is_int($value) || $value < 1) {
            throw new InvalidArgumentException(
                'Codex stale heartbeat configuration must be a positive integer.',
            );
        }

        return $value;
    }

    /**
     * Return the bounded grace period used before forced process termination.
     */
    private function cancellationGraceSeconds(): int
    {
        $value = config(
            'codex-app-server.cancellation_grace_seconds',
            10,
        );

        if (! is_int($value) || $value < 1) {
            throw new InvalidArgumentException(
                'Codex cancellation grace configuration must be a positive integer.',
            );
        }

        return $value;
    }
}
