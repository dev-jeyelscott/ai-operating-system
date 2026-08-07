<?php

declare(strict_types=1);

namespace App\Application\Codex\Persistence;

use App\Application\Codex\Data\CodexGatewayInitialization;
use App\Application\Codex\Data\CodexProcessContext;
use App\Application\Codex\Data\CodexProcessStatus;
use App\Domain\Audit\AuditEventType;
use App\Domain\Codex\ProviderSessionStatus;
use App\Domain\Executions\ExecutionAttemptStatus;
use App\Models\ExecutionAttempt;
use App\Models\ProviderSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Owns durable provider-session creation, heartbeat and cancellation metadata.
 */
final readonly class ProviderSessionManager
{
    /**
     * Inject lifecycle-event persistence.
     */
    public function __construct(
        private RecordProviderLifecycleEvent $events,
    ) {}

    /**
     * Idempotently persist one initialized provider process session.
     */
    public function start(
        ExecutionAttempt $attempt,
        CodexProcessContext $context,
        CodexGatewayInitialization $initialization,
        CodexProcessStatus $processStatus,
        ?CarbonImmutable $at = null,
    ): ProviderSession {
        $occurredAt = $at ?? CarbonImmutable::now();

        $attempt->loadMissing(
            'execution.project',
        );

        if (
            $attempt->execution_id !== $context->executionId
            || $attempt->id
                !== $context->executionAttemptId
            || $attempt->execution_provider !== 'codex'
        ) {
            throw new LogicException(
                'Codex provider session lineage does not match its execution attempt.',
            );
        }

        return DB::transaction(function () use (
            $attempt,
            $context,
            $initialization,
            $processStatus,
            $occurredAt,
        ): ProviderSession {
            $lockedAttempt = ExecutionAttempt::query()
                ->whereKey($attempt->id)
                ->lockForUpdate()
                ->firstOrFail();

            $existing = ProviderSession::query()
                ->where(
                    'execution_attempt_id',
                    $lockedAttempt->id,
                )
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->id !== $context->providerSessionId) {
                    throw new LogicException(
                        'Execution attempt already owns a different provider session.',
                    );
                }

                return $existing;
            }

            $session = ProviderSession::query()->create([
                'id' => $context->providerSessionId,
                'organization_id' => $attempt->execution->project
                    ->organization_id,
                'project_id' => $attempt->execution->project_id,
                'execution_id' => $attempt->execution_id,
                'execution_attempt_id' => $attempt->id,
                'provider' => 'codex',
                'binary_version' => $initialization->binaryVersion,
                'protocol_version' => $initialization->protocolVersion,
                'protocol_schema_fingerprint' => $initialization->schemaFingerprint,
                'model_identifier' => $attempt->model_identifier,
                'sandbox_profile' => $context->sandboxProfile,
                'network_policy' => $context->networkPolicy,
                'runtime_process_id' => $processStatus->processId,
                'status' => ProviderSessionStatus::Active,
                'last_provider_sequence' => 0,
                'process_started_at' => $occurredAt,
                'initialized_at' => $occurredAt,
                'heartbeat_at' => $occurredAt,
            ]);

            if (
                $lockedAttempt->status
                === ExecutionAttemptStatus::Running
            ) {
                $lockedAttempt->forceFill([
                    'heartbeat_at' => $occurredAt,
                ])->save();
            }

            $session->setRelation(
                'execution',
                $attempt->execution,
            );

            $this->events->record(
                session: $session,
                eventType: AuditEventType::ProviderSessionStarted,
                payload: [
                    'binary_version' => $session->binary_version,
                    'protocol_version' => $session->protocol_version,
                    'sandbox_profile' => $session->sandbox_profile,
                ],
                deduplicationKey: 'provider-session-started:'.$session->id,
            );

            return $session;
        });
    }

    /**
     * Persist provider and execution-attempt liveness without emitting noisy
     * audit events for every heartbeat.
     */
    public function heartbeat(
        ProviderSession $session,
        ?CarbonImmutable $at = null,
    ): bool {
        $heartbeatAt = $at ?? CarbonImmutable::now();

        return DB::transaction(function () use (
            $session,
            $heartbeatAt,
        ): bool {
            $locked = ProviderSession::query()
                ->whereKey($session->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status->isTerminal()) {
                return false;
            }

            $locked->forceFill([
                'heartbeat_at' => $heartbeatAt,
            ])->save();

            $attempt = ExecutionAttempt::query()
                ->whereKey($locked->execution_attempt_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $attempt->status
                === ExecutionAttemptStatus::Running
            ) {
                $attempt->forceFill([
                    'heartbeat_at' => $heartbeatAt,
                ])->save();
            }

            return true;
        });
    }

    /**
     * Mark provider-session cancellation metadata idempotently.
     *
     * Execution cancellation itself remains owned by
     * ExecutionResilienceManager.
     */
    public function cancel(
        ProviderSession $session,
        ?CarbonImmutable $at = null,
    ): bool {
        $cancelledAt = $at ?? CarbonImmutable::now();

        return DB::transaction(function () use (
            $session,
            $cancelledAt,
        ): bool {
            $locked = ProviderSession::query()
                ->whereKey($session->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status->isTerminal()) {
                return false;
            }

            $locked->forceFill([
                'status' => ProviderSessionStatus::Cancelled,
                'cancellation_requested_at' => $cancelledAt,
                'terminal_at' => $cancelledAt,
                'terminal_status' => 'cancelled',
                'heartbeat_at' => $cancelledAt,
            ])->save();

            $locked->loadMissing('execution');

            $this->events->record(
                session: $locked,
                eventType: AuditEventType::ProviderSessionCancelled,
                deduplicationKey: 'provider-session-cancelled:'.$locked->id,
            );

            return true;
        });
    }
}
