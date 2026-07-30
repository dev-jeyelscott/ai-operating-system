<?php

declare(strict_types=1);

namespace App\Application\Tickets;

use App\Application\Shared\Exceptions\ConflictException;
use App\Domain\Approvals\ApprovalStatus;
use App\Domain\Approvals\ApprovalType;
use App\Domain\Audit\AuditEventType;
use App\Domain\Executions\ExecutionStatus;
use App\Domain\Tickets\TicketStatus;
use App\Models\Approval;
use App\Models\AuditEvent;
use App\Models\Execution;
use App\Models\MergeDecision;
use App\Models\Project;
use App\Models\Roadmap;
use App\Models\RoadmapTask;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Applies the authoritative Phase 7 ticket transition policy under row locks.
 */
final readonly class TransitionTicketStatus
{
    /**
     * Inject the lifecycle event recorder used by every successful transition.
     */
    public function __construct(
        private RecordTicketLifecycleEvents $events,
    ) {}

    /**
     * Lock the complete ticket lineage and apply one replay-safe transition.
     */
    public function handle(
        int $organizationId,
        int $projectId,
        int $roadmapId,
        int $ticketId,
        TicketStatus $target,
        string $idempotencyKey,
        string $actorId,
        string $correlationId,
        ?string $executionId = null,
    ): RoadmapTask {
        $this->assertIdentifiers(
            organizationId: $organizationId,
            projectId: $projectId,
            roadmapId: $roadmapId,
            ticketId: $ticketId,
            idempotencyKey: $idempotencyKey,
            actorId: $actorId,
            correlationId: $correlationId,
        );

        $idempotencyKeyHash = hash(
            'sha256',
            $idempotencyKey,
        );

        $requestFingerprint = TicketCommandFingerprint::make([
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'roadmap_id' => $roadmapId,
            'ticket_id' => $ticketId,
            'target' => $target->value,
            'actor_id' => $actorId,
            'execution_id' => $executionId,
        ]);

        return DB::transaction(
            function () use (
                $organizationId,
                $projectId,
                $roadmapId,
                $ticketId,
                $target,
                $idempotencyKeyHash,
                $requestFingerprint,
                $actorId,
                $correlationId,
                $executionId,
            ): RoadmapTask {
                $project = Project::query()
                    ->forOrganization($organizationId)
                    ->whereKey($projectId)
                    ->lock('for share')
                    ->firstOrFail();

                $execution = $this->lockedExecution(
                    project: $project,
                    target: $target,
                    executionId: $executionId,
                );

                $roadmap = Roadmap::query()
                    ->where('project_id', $project->id)
                    ->whereKey($roadmapId)
                    ->lock('for share')
                    ->firstOrFail();

                $ticket = RoadmapTask::query()
                    ->where('roadmap_id', $roadmap->id)
                    ->whereKey($ticketId)
                    ->lockForUpdate()
                    ->firstOrFail();

                return $this->applyLocked(
                    project: $project,
                    roadmap: $roadmap,
                    ticket: $ticket,
                    target: $target,
                    idempotencyKeyHash: $idempotencyKeyHash,
                    requestFingerprint: $requestFingerprint,
                    execution: $execution,
                    actorId: $actorId,
                    correlationId: $correlationId,
                    executionId: $executionId,
                );
            },
            attempts: 3,
        );
    }

    /**
     * Apply a transition using lineage rows already locked by an enclosing
     * orchestration transaction in Project, Execution, Roadmap, Ticket order.
     */
    public function handleLocked(
        Project $project,
        Roadmap $roadmap,
        RoadmapTask $ticket,
        TicketStatus $target,
        string $idempotencyKey,
        string $actorId,
        string $correlationId,
        ?Execution $execution = null,
    ): RoadmapTask {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException(
                'A locked ticket transition requires an active transaction.',
            );
        }

        $executionId = $execution?->id;

        $this->assertIdentifiers(
            organizationId: $project->organization_id,
            projectId: $project->id,
            roadmapId: $roadmap->id,
            ticketId: $ticket->id,
            idempotencyKey: $idempotencyKey,
            actorId: $actorId,
            correlationId: $correlationId,
        );

        if (
            $roadmap->project_id !== $project->id
            || $ticket->roadmap_id !== $roadmap->id
            || (
                $execution !== null
                && $execution->project_id !== $project->id
            )
        ) {
            throw new \LogicException(
                'Locked ticket transition lineage is invalid.',
            );
        }

        $idempotencyKeyHash = hash(
            'sha256',
            $idempotencyKey,
        );

        $requestFingerprint = TicketCommandFingerprint::make([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'roadmap_id' => $roadmap->id,
            'ticket_id' => $ticket->id,
            'target' => $target->value,
            'actor_id' => $actorId,
            'execution_id' => $executionId,
        ]);

        return $this->applyLocked(
            project: $project,
            roadmap: $roadmap,
            ticket: $ticket,
            target: $target,
            idempotencyKeyHash: $idempotencyKeyHash,
            requestFingerprint: $requestFingerprint,
            execution: $execution,
            actorId: $actorId,
            correlationId: $correlationId,
            executionId: $executionId,
        );
    }

    /**
     * Validate policy, persist the status, and append the lifecycle event.
     */
    private function applyLocked(
        Project $project,
        Roadmap $roadmap,
        RoadmapTask $ticket,
        TicketStatus $target,
        string $idempotencyKeyHash,
        string $requestFingerprint,
        ?Execution $execution,
        string $actorId,
        string $correlationId,
        ?string $executionId,
    ): RoadmapTask {
        if (
            $roadmap->project_id !== $project->id
            || $ticket->roadmap_id !== $roadmap->id
        ) {
            throw new \LogicException(
                'Ticket transition lineage is invalid.',
            );
        }

        $deduplicationKey =
            RecordTicketLifecycleEvents::deduplicationKey(
                eventType: AuditEventType::TicketStatusTransitioned,
                ticketId: $ticket->id,
                idempotencyKeyHash: $idempotencyKeyHash,
            );

        if (
            $this->isExactReplay(
                project: $project,
                deduplicationKey: $deduplicationKey,
                requestFingerprint: $requestFingerprint,
            )
        ) {
            return $ticket;
        }

        $from = $ticket->status;

        $this->assertTransitionAllowed(
            ticket: $ticket,
            from: $from,
            to: $target,
            projectId: $project->id,
            execution: $execution,
        );

        $occurredAt = CarbonImmutable::now();

        $ticket->applyAuthoritativeStatusTransition(
            target: $target,
            occurredAt: $occurredAt,
        );

        $ticket->refresh();

        $this->events->transitioned(
            project: $project,
            ticket: $ticket,
            from: $from,
            to: $target,
            actorId: $actorId,
            correlationId: $correlationId,
            idempotencyKeyHash: $idempotencyKeyHash,
            requestFingerprint: $requestFingerprint,
            executionId: $executionId,
            occurredAt: $occurredAt,
        );

        return $ticket;
    }

    /**
     * Lock and validate the development execution required for a For QA
     * transition.
     */
    private function lockedExecution(
        Project $project,
        TicketStatus $target,
        ?string $executionId,
    ): ?Execution {
        if ($target !== TicketStatus::ForQa) {
            return null;
        }

        if (
            $executionId === null
            || trim($executionId) === ''
        ) {
            throw new InvalidArgumentException(
                'A successful development execution is required before For QA.',
            );
        }

        return Execution::query()
            ->forProject($project->id)
            ->whereKey($executionId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Enforce the authoritative ticket state machine and required evidence.
     */
    private function assertTransitionAllowed(
        RoadmapTask $ticket,
        TicketStatus $from,
        TicketStatus $to,
        int $projectId,
        ?Execution $execution,
    ): void {
        $allowed = match ($from) {
            TicketStatus::Backlog => $to === TicketStatus::Ready,

            TicketStatus::Ready => $to === TicketStatus::InProgress,

            TicketStatus::ChangesRequested => $to === TicketStatus::InProgress
                && $this->hasExecutionApproval(
                    ticket: $ticket,
                    projectId: $projectId,
                ),

            TicketStatus::InProgress => $to === TicketStatus::ForQa
                && $this->isSuccessfulDevelopmentExecution(
                    $execution,
                ),

            default => false,
        };

        if (! $allowed) {
            throw new ConflictException(sprintf(
                'Ticket status transition from %s to %s is not permitted.',
                $from->value,
                $to->value,
            ));
        }
    }

    /**
     * Confirm that the supplied Layer 2 execution completed successfully.
     */
    private function isSuccessfulDevelopmentExecution(
        ?Execution $execution,
    ): bool {
        return $execution !== null
            && Str::startsWith(
                $execution->capability,
                'development.',
            )
            && $execution->status === ExecutionStatus::Completed
            && $execution->cancel_requested_at === null
            && $execution->cancelled_at === null;
    }

    /**
     * Determine whether an explicit execution approval or an authorized
     * request-changes decision permits another Layer 2 cycle.
     */
    private function hasExecutionApproval(
        RoadmapTask $ticket,
        int $projectId,
    ): bool {
        $explicitApproval = Approval::query()
            ->forProject($projectId)
            ->where(
                'type',
                ApprovalType::Execution->value,
            )
            ->where(
                'status',
                ApprovalStatus::Approved->value,
            )
            ->get([
                'request_payload',
            ])
            ->contains(
                static function (
                    Approval $approval,
                ) use ($ticket): bool {
                    $payload = $approval->request_payload;

                    return (
                        $payload['roadmap_task_id'] ?? null
                    ) === $ticket->id
                        || (
                            $payload['ticket_id'] ?? null
                        ) === $ticket->stable_id;
                },
            );

        if ($explicitApproval) {
            return true;
        }

        return MergeDecision::query()
            ->forProject($projectId)
            ->authorizesChangesRequestedRework()
            ->where(
                'roadmap_task_id',
                $ticket->id,
            )
            ->exists();
    }

    /**
     * Return true only when the same transition request was already committed.
     */
    private function isExactReplay(
        Project $project,
        string $deduplicationKey,
        string $requestFingerprint,
    ): bool {
        $existing = AuditEvent::query()
            ->where(
                'organization_id',
                $project->organization_id,
            )
            ->where(
                'deduplication_key',
                $deduplicationKey,
            )
            ->first();

        if ($existing === null) {
            return false;
        }

        $existingFingerprint =
            $existing->metadata['request_fingerprint'] ?? null;

        if (
            ! is_string($existingFingerprint)
            || ! hash_equals(
                $existingFingerprint,
                $requestFingerprint,
            )
        ) {
            throw new ConflictException(
                'The ticket transition idempotency key was reused with different input.',
            );
        }

        return true;
    }

    /**
     * Validate bounded identifiers before any authoritative state is queried.
     */
    private function assertIdentifiers(
        int $organizationId,
        int $projectId,
        int $roadmapId,
        int $ticketId,
        string $idempotencyKey,
        string $actorId,
        string $correlationId,
    ): void {
        if (
            min(
                $organizationId,
                $projectId,
                $roadmapId,
                $ticketId,
            ) < 1
        ) {
            throw new InvalidArgumentException(
                'Ticket transition identifiers must be positive.',
            );
        }

        foreach (
            [
                'idempotency key' => $idempotencyKey,
                'actor identifier' => $actorId,
                'correlation identifier' => $correlationId,
            ] as $name => $value
        ) {
            if (
                $value === ''
                || trim($value) !== $value
                || mb_strlen($value) > 128
                || preg_match(
                    '/\A[A-Za-z0-9][A-Za-z0-9._:-]*\z/',
                    $value,
                ) !== 1
            ) {
                throw new InvalidArgumentException(
                    sprintf(
                        'The ticket transition %s is invalid.',
                        $name,
                    ),
                );
            }
        }
    }
}
