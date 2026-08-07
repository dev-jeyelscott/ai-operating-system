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
 * Evaluates suspicious Codex attempts using durable execution and provider
 * liveness facts without prematurely releasing leases or scheduling retries.
 */
final readonly class CodexLivenessManager
{
    /**
     * Inject runtime inspection and durable provider-session services.
     */
    public function __construct(
        private CodexRuntimeControl $runtime,
        private ProviderSessionManager $sessions,
    ) {}

    /**
     * Evaluate one running Codex attempt for cancellation, timeout, stale
     * heartbeat, provider divergence, or process ownership loss.
     */
    public function evaluate(
        int $executionAttemptId,
        ?CarbonImmutable $at = null,
    ): void {
        if ($executionAttemptId < 1) {
            throw new InvalidArgumentException(
                'Codex liveness evaluation requires a positive execution attempt identifier.',
            );
        }

        $evaluatedAt = $at ?? CarbonImmutable::now();

        $attempt = ExecutionAttempt::query()
            ->with('execution')
            ->find($executionAttemptId);

        if ($attempt === null) {
            return;
        }

        if ($attempt->execution_provider !== 'codex') {
            return;
        }

        if ($attempt->status !== ExecutionAttemptStatus::Running) {
            return;
        }

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
         * Once recovery has already been recorded, evaluation is idempotent.
         * Cleanup and reconciliation own the next transition.
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
         * If the provider session is already terminal while the execution
         * attempt remains running, preserve the divergence for reconciliation.
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
         * Only terminate when runtime inspection conclusively proves that the
         * recorded process identity is still the original running process.
         */
        if ($runtimeState === CodexRuntimeState::Running) {
            $runtimeState = $this->runtime->terminate(
                session: $session,
                graceSeconds: $this->cancellationGraceSeconds(),
            );
        }

        $this->sessions->markRecoveryRequired(
            session: $session,
            reason: $this->runtimeRecoveryReason(
                originalReason: $reason,
                runtimeState: $runtimeState,
            ),
            at: $evaluatedAt,
        );
    }

    /**
     * Determine whether durable execution facts require recovery evaluation.
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

        $heartbeatAt = $session->heartbeat_at
            ?? $attempt->heartbeat_at;

        if ($heartbeatAt === null) {
            return 'codex.heartbeat_missing';
        }

        $staleBefore = $evaluatedAt->subSeconds(
            $this->staleHeartbeatSeconds(),
        );

        if (
            $heartbeatAt->lessThanOrEqualTo(
                $staleBefore,
            )
        ) {
            return 'codex.heartbeat_stale';
        }

        return null;
    }

    /**
     * Convert runtime inspection results into explicit recovery provenance.
     */
    private function runtimeRecoveryReason(
        string $originalReason,
        CodexRuntimeState $runtimeState,
    ): string {
        return match ($runtimeState) {
            CodexRuntimeState::Exited => $originalReason,
            CodexRuntimeState::IdentityMismatch => 'codex.runtime_identity_mismatch',
            CodexRuntimeState::Unreachable => 'codex.runtime_unreachable',
            CodexRuntimeState::Running => 'codex.process_termination_failed',
        };
    }

    /**
     * Return the configured stale heartbeat threshold.
     */
    private function staleHeartbeatSeconds(): int
    {
        $seconds = config(
            'codex-app-server.persistence.stale_heartbeat_seconds',
            45,
        );

        if (! is_int($seconds) || $seconds < 1) {
            throw new InvalidArgumentException(
                'Codex stale heartbeat threshold must be a positive integer.',
            );
        }

        return $seconds;
    }

    /**
     * Return the bounded grace period before forced Codex termination.
     */
    private function cancellationGraceSeconds(): int
    {
        $seconds = config(
            'codex-app-server.cancellation_grace_seconds',
            10,
        );

        if (! is_int($seconds) || $seconds < 1) {
            throw new InvalidArgumentException(
                'Codex cancellation grace period must be a positive integer.',
            );
        }

        return $seconds;
    }
}
