<?php

declare(strict_types=1);

namespace App\Application\Codex\Recovery;

use App\Application\Codex\Persistence\ProviderSessionManager;
use App\Application\Tickets\TicketLeaseManager;
use App\Models\ProviderSession;
use App\Models\TicketExecutionLease;
use LogicException;
use Throwable;

/**
 * Keeps provider and ticket-lease liveness aligned for the current attempt.
 */
final readonly class CodexHeartbeatRecorder
{
    /**
     * Inject provider and ticket liveness services.
     */
    public function __construct(
        private ProviderSessionManager $sessions,
        private TicketLeaseManager $leases,
    ) {}

    /**
     * Persist one authenticated heartbeat from the local owning Codex worker.
     */
    public function record(
        ProviderSession $session,
        TicketExecutionLease $lease,
        string $owner,
        bool $providerMessage = false,
    ): void {
        if (
            $lease->execution_id !== $session->execution_id
            || $lease->project_id !== $session->project_id
        ) {
            throw new LogicException(
                'Codex heartbeat lease lineage does not match provider session.',
            );
        }

        try {
            $changed = $this->sessions->heartbeat(
                session: $session,
                providerMessage: $providerMessage,
            );

            if (! $changed) {
                throw new LogicException(
                    'Codex provider session rejected the heartbeat.',
                );
            }

            $this->leases->heartbeat(
                organizationId: $session->organization_id,
                projectId: $session->project_id,
                leaseId: $lease->id,
                executionId: $session->execution_id,
                owner: $owner,
            );
        } catch (Throwable $exception) {
            $this->sessions->markRecoveryRequired(
                session: $session,
                reason: 'codex.heartbeat_divergence',
            );

            throw $exception;
        }
    }
}
