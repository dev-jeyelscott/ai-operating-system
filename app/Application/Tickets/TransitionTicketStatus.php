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
    public function __construct(
        private RecordTicketLifecycleEvents $events,
    ) {}

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

        $idempotencyKeyHash = hash('sha256', $idempotencyKey);
        $requestFingerprint = TicketCommandFingerprint::make([
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'roadmap_id' => $roadmapId,
            'ticket_id' => $ticketId,
            'target' => $target->value,
            'actor_id' => $actorId,
            'execution_id' => $executionId,
        ]);

        return DB::transaction(function () use (
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

            Roadmap::query()
                ->where('project_id', $project->id)
                ->whereKey($roadmapId)
                ->lock('for share')
                ->firstOrFail();

            $execution = $this->lockedExecution(
                project: $project,
                target: $target,
                executionId: $executionId,
            );

            $ticket = RoadmapTask::query()
                ->where('roadmap_id', $roadmapId)
                ->whereKey($ticketId)
                ->lockForUpdate()
                ->firstOrFail();

            $deduplicationKey = RecordTicketLifecycleEvents::deduplicationKey(
                eventType: AuditEventType::TicketStatusTransitioned,
                ticketId: $ticket->id,
                idempotencyKeyHash: $idempotencyKeyHash,
            );

            if ($this->isExactReplay(
                project: $project,
                deduplicationKey: $deduplicationKey,
                requestFingerprint: $requestFingerprint,
            )) {
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
        }, attempts: 3);
    }

    private function lockedExecution(
        Project $project,
        TicketStatus $target,
        ?string $executionId,
    ): ?Execution {
        if ($target !== TicketStatus::ForQa) {
            return null;
        }

        if ($executionId === null || trim($executionId) === '') {
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
                && $this->hasExecutionApproval($ticket, $projectId),
            TicketStatus::InProgress => $to === TicketStatus::ForQa
                && $this->isSuccessfulDevelopmentExecution($execution),
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

    private function isSuccessfulDevelopmentExecution(
        ?Execution $execution,
    ): bool {
        return $execution !== null
            && Str::startsWith($execution->capability, 'development.')
            && $execution->status === ExecutionStatus::Completed
            && $execution->cancel_requested_at === null
            && $execution->cancelled_at === null;
    }

    private function hasExecutionApproval(
        RoadmapTask $ticket,
        int $projectId,
    ): bool {
        return Approval::query()
            ->forProject($projectId)
            ->where('type', ApprovalType::Execution)
            ->where('status', ApprovalStatus::Approved)
            ->get(['request_payload'])
            ->contains(static function (Approval $approval) use ($ticket): bool {
                $payload = $approval->request_payload;

                return ($payload['roadmap_task_id'] ?? null) === $ticket->id
                    || ($payload['ticket_id'] ?? null) === $ticket->stable_id;
            });
    }

    private function isExactReplay(
        Project $project,
        string $deduplicationKey,
        string $requestFingerprint,
    ): bool {
        $existing = AuditEvent::query()
            ->where('organization_id', $project->organization_id)
            ->where('deduplication_key', $deduplicationKey)
            ->first();

        if ($existing === null) {
            return false;
        }

        $existingFingerprint = $existing->metadata['request_fingerprint'] ?? null;

        if (! is_string($existingFingerprint)
            || ! hash_equals($existingFingerprint, $requestFingerprint)) {
            throw new ConflictException(
                'The ticket transition idempotency key was reused with different input.',
            );
        }

        return true;
    }

    private function assertIdentifiers(
        int $organizationId,
        int $projectId,
        int $roadmapId,
        int $ticketId,
        string $idempotencyKey,
        string $actorId,
        string $correlationId,
    ): void {
        if (min($organizationId, $projectId, $roadmapId, $ticketId) < 1) {
            throw new InvalidArgumentException(
                'Ticket transition identifiers must be positive.',
            );
        }

        foreach ([
            'idempotency key' => $idempotencyKey,
            'actor identifier' => $actorId,
            'correlation identifier' => $correlationId,
        ] as $name => $value) {
            if ($value === ''
                || trim($value) !== $value
                || mb_strlen($value) > 128
                || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]*\z/', $value) !== 1) {
                throw new InvalidArgumentException(
                    sprintf('The ticket transition %s is invalid.', $name),
                );
            }
        }
    }
}
