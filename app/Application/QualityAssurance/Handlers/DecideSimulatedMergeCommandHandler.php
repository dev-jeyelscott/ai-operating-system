<?php

declare(strict_types=1);

namespace App\Application\QualityAssurance\Handlers;

use App\Application\Audit\RecordAuditEvent;
use App\Application\Events\Contracts\DomainEventOutbox;
use App\Application\QualityAssurance\Commands\DecideSimulatedMergeCommand;
use App\Application\QualityAssurance\SimulatedMergeDecisionPolicy;
use App\Application\Security\RedactSensitiveData;
use App\Application\Shared\Commands\CommandResult;
use App\Application\Shared\Exceptions\ConflictException;
use App\Application\Tickets\RecordTicketLifecycleEvents;
use App\Domain\Audit\AuditActorType;
use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\AuditSubjectType;
use App\Domain\Events\DomainEventActor;
use App\Domain\Events\DomainEventEnvelope;
use App\Domain\Executions\ExecutionCapability;
use App\Domain\Executions\ExecutionStatus;
use App\Domain\QualityAssurance\MergeDecisionAction;
use App\Domain\Tickets\TicketStatus;
use App\Models\Execution;
use App\Models\MergeDecision;
use App\Models\Project;
use App\Models\QaAssessment;
use App\Models\Roadmap;
use App\Models\RoadmapTask;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;

/**
 * Authorizes, validates, and records one simulated merge decision atomically.
 */
final readonly class DecideSimulatedMergeCommandHandler
{
    /**
     * Inject existing audit, outbox, ticket-event, and redaction services.
     */
    public function __construct(
        private DomainEventOutbox $outbox,
        private RecordAuditEvent $auditEvents,
        private RecordTicketLifecycleEvents $ticketEvents,
        private RedactSensitiveData $redactor,
        private SimulatedMergeDecisionPolicy $decisionPolicy,
    ) {}

    /**
     * Apply one decision or return its exact previously committed result.
     */
    public function handle(
        DecideSimulatedMergeCommand $command,
    ): CommandResult {
        $this->validateCommand($command);

        $reason = $this->normalizeReason($command->reason);

        if (
            $this->decisionPolicy->reasonRequiredFor($command->action)
            && $reason === null
        ) {
            throw new InvalidArgumentException(sprintf(
                'A reason is required for simulated merge action [%s].',
                $command->action->value,
            ));
        }

        $idempotencyKey = $this->normalizeIdentifier(
            value: $command->requestIdempotencyKey,
            name: 'merge decision idempotency',
            maximumLength: 191,
        );

        $correlationId = $this->normalizeIdentifier(
            value: $command->correlationId,
            name: 'merge decision correlation',
            maximumLength: 128,
        );

        $causationId = $this->normalizeOptionalIdentifier(
            value: $command->causationId,
            name: 'merge decision causation',
            maximumLength: 128,
        );

        $idempotencyKeyHash = hash('sha256', $idempotencyKey);

        $requestFingerprint = $this->requestFingerprint(
            command: $command,
            reason: $reason,
        );

        /*
         * The assessment-to-ticket relationship is immutable. Reading the
         * locator before the transaction lets the transaction acquire rows in
         * Project -> Roadmap -> Ticket -> Assessment order.
         */
        $assessmentLocator = QaAssessment::query()
            ->forProject($command->projectId)
            ->whereKey($command->qaAssessmentId)
            ->firstOrFail([
                'id',
                'roadmap_task_id',
            ]);

        $ticketLocator = RoadmapTask::query()
            ->whereKey($assessmentLocator->roadmap_task_id)
            ->firstOrFail([
                'id',
                'roadmap_id',
            ]);

        return DB::transaction(
            function () use (
                $command,
                $reason,
                $correlationId,
                $causationId,
                $idempotencyKeyHash,
                $requestFingerprint,
                $assessmentLocator,
                $ticketLocator,
            ): CommandResult {
                $project = Project::query()
                    ->forOrganization($command->organizationId)
                    ->whereKey($command->projectId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $actor = User::query()
                    ->whereKey($command->actorUserId)
                    ->firstOrFail();

                Gate::forUser($actor)->authorize(
                    'approve',
                    $project,
                );

                $roadmap = Roadmap::query()
                    ->where('project_id', $project->id)
                    ->whereKey($ticketLocator->roadmap_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $ticket = RoadmapTask::query()
                    ->where('roadmap_id', $roadmap->id)
                    ->whereKey($ticketLocator->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $assessment = QaAssessment::query()
                    ->forProject($project->id)
                    ->where('roadmap_task_id', $ticket->id)
                    ->whereKey($assessmentLocator->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $reviewExecution = Execution::query()
                    ->forProject($project->id)
                    ->whereKey($assessment->review_execution_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                /*
                 * Locking the assessment serializes concurrent decisions for
                 * the same QA result. The second caller observes the first
                 * caller's committed idempotency or terminal record.
                 */
                $existing = MergeDecision::query()
                    ->forProject($project->id)
                    ->where(
                        'idempotency_key_hash',
                        $idempotencyKeyHash,
                    )
                    ->first();

                if ($existing !== null) {
                    if (! hash_equals(
                        $existing->request_fingerprint,
                        $requestFingerprint,
                    )) {
                        throw new ConflictException(
                            'The simulated merge decision idempotency key was reused with different input.',
                        );
                    }

                    return $this->successfulResult(
                        decision: $existing,
                        replayed: true,
                    );
                }

                $terminalDecision = MergeDecision::query()
                    ->forProject($project->id)
                    ->where(
                        'qa_assessment_id',
                        $assessment->id,
                    )
                    ->where('terminal_marker', 'T')
                    ->first();

                if ($terminalDecision !== null) {
                    throw new ConflictException(
                        'The simulated merge assessment already has a terminal decision.',
                    );
                }

                $this->assertCurrentState(
                    assessment: $assessment,
                    reviewExecution: $reviewExecution,
                    ticket: $ticket,
                    expectedFingerprint: $command->expectedAssessmentFingerprint,
                );

                $this->assertActionAllowed(
                    action: $command->action,
                    assessment: $assessment,
                );

                $occurredAt = CarbonImmutable::now();
                $statusBefore = $ticket->status;
                $statusAfter = $this->targetTicketStatus(
                    action: $command->action,
                    current: $statusBefore,
                );

                $ticketEventId = null;

                if ($statusAfter !== $statusBefore) {
                    $ticket->applyAuthoritativeStatusTransition(
                        target: $statusAfter,
                        occurredAt: $occurredAt,
                    );

                    $ticket->refresh();

                    $ticketEventId = $this->ticketEvents->transitioned(
                        project: $project,
                        ticket: $ticket,
                        from: $statusBefore,
                        to: $statusAfter,
                        actorId: sprintf(
                            'simulated-merge-decision-user-%d',
                            $actor->id,
                        ),
                        correlationId: $correlationId,
                        idempotencyKeyHash: $idempotencyKeyHash,
                        requestFingerprint: $requestFingerprint,
                        executionId: $reviewExecution->id,
                        occurredAt: $occurredAt,
                    );
                }

                $decision = MergeDecision::query()->create([
                    'project_id' => $project->id,
                    'roadmap_task_id' => $ticket->id,
                    'qa_assessment_id' => $assessment->id,
                    'actor_user_id' => $actor->id,
                    'action' => $command->action,
                    'reason' => $reason,
                    'idempotency_key_hash' => $idempotencyKeyHash,
                    'request_fingerprint' => $requestFingerprint,
                    'correlation_id' => $correlationId,
                    'causation_id' => $causationId,
                    'assessment_decision' => $assessment->decision->value,
                    'assessment_fingerprint' => $assessment
                        ->canonical_assessment_fingerprint,
                    'ticket_status_before' => $statusBefore->value,
                    'ticket_status_after' => $statusAfter->value,
                    'terminal_marker' => $command->action->isTerminal()
                        ? 'T'
                        : null,
                    'simulated' => true,
                    'actual_state' => 'unverified',
                    'decided_at' => $occurredAt,
                ]);

                $this->recordDecisionEvent(
                    project: $project,
                    assessment: $assessment,
                    reviewExecution: $reviewExecution,
                    ticket: $ticket,
                    decision: $decision,
                    actor: $actor,
                    correlationId: $correlationId,
                    causationId: $ticketEventId
                        ?? $causationId,
                    idempotencyKeyHash: $idempotencyKeyHash,
                );

                return $this->successfulResult(
                    decision: $decision,
                    replayed: false,
                );
            },
            attempts: 3,
        );
    }

    /**
     * Validate primitive command fields before querying authoritative state.
     */
    private function validateCommand(
        DecideSimulatedMergeCommand $command,
    ): void {
        if (
            min(
                $command->organizationId,
                $command->projectId,
                $command->actorUserId,
            ) < 1
        ) {
            throw new InvalidArgumentException(
                'Simulated merge decision identifiers must be positive.',
            );
        }

        if (! Str::isUlid($command->qaAssessmentId)) {
            throw new InvalidArgumentException(
                'The QA assessment identifier must be a valid ULID.',
            );
        }

        if (
            preg_match(
                '/\A[a-f0-9]{64}\z/',
                $command->expectedAssessmentFingerprint,
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'The expected QA assessment fingerprint must be a lowercase SHA-256 value.',
            );
        }
    }

    /**
     * Require one completed, current, simulation-only QA lineage.
     */
    private function assertCurrentState(
        QaAssessment $assessment,
        Execution $reviewExecution,
        RoadmapTask $ticket,
        string $expectedFingerprint,
    ): void {
        if ($assessment->status !== QaAssessment::STATUS_COMPLETED) {
            throw new ConflictException(
                'The QA assessment is not completed.',
            );
        }

        if (
            ! ExecutionCapability::QualityAssuranceReview
                ->accepts($reviewExecution->capability)
            || $reviewExecution->status
            !== ExecutionStatus::Completed
        ) {
            throw new ConflictException(
                'The QA assessment does not belong to a completed simulated Layer 3 execution.',
            );
        }

        if ($ticket->status !== TicketStatus::ForQa) {
            throw new ConflictException(sprintf(
                'The ticket must be in [%s] before a simulated merge decision.',
                TicketStatus::ForQa->value,
            ));
        }

        if ($assessment->target_branch !== 'develop') {
            throw new ConflictException(
                'A simulated merge decision may only target develop.',
            );
        }

        $currentFingerprint =
            $assessment->canonical_assessment_fingerprint;

        if (
            ! is_string($currentFingerprint)
            || ! hash_equals(
                $currentFingerprint,
                $expectedFingerprint,
            )
        ) {
            throw new ConflictException(
                'The simulated merge decision references stale QA assessment content.',
            );
        }
    }

    /**
     * Enforce action-specific deterministic policy.
     */
    private function assertActionAllowed(
        MergeDecisionAction $action,
        QaAssessment $assessment,
    ): void {
        if ($action !== MergeDecisionAction::Approve) {
            return;
        }

        if (! $this->decisionPolicy->canApprove($assessment)) {
            $blockingCodes = $this->decisionPolicy->blockingFindingCodes(
                $assessment->unresolved_findings,
            );

            if ($blockingCodes !== []) {
                throw new ConflictException(sprintf(
                    'Simulated merge approval is blocked by unresolved findings: %s.',
                    implode(', ', $blockingCodes),
                ));
            }

            throw new ConflictException(sprintf(
                'QA decision [%s] does not permit simulated merge approval.',
                $assessment->decision->value,
            ));
        }
    }

    /**
     * Resolve the authoritative ticket state produced by an action.
     */
    private function targetTicketStatus(
        MergeDecisionAction $action,
        TicketStatus $current,
    ): TicketStatus {
        return match ($action) {
            MergeDecisionAction::Approve => TicketStatus::ApprovedForMerge,

            MergeDecisionAction::RequestChanges => TicketStatus::ChangesRequested,

            MergeDecisionAction::Escalate,
            MergeDecisionAction::Defer => $current,
        };
    }

    /**
     * Append the user decision to the transactional outbox and audit history.
     */
    private function recordDecisionEvent(
        Project $project,
        QaAssessment $assessment,
        Execution $reviewExecution,
        RoadmapTask $ticket,
        MergeDecision $decision,
        User $actor,
        string $correlationId,
        ?string $causationId,
        string $idempotencyKeyHash,
    ): void {
        $eventType = $this->eventType(
            $decision->action,
        );

        $eventId = (string) Str::ulid();

        $metadata = [
            'merge_decision_id' => $decision->id,
            'qa_assessment_id' => $assessment->id,
            'roadmap_id' => $ticket->roadmap_id,
            'roadmap_task_id' => $ticket->id,
            'ticket_id' => $ticket->stable_id,
            'review_execution_id' => $reviewExecution->id,
            'action' => $decision->action->value,
            'qa_recommendation' => $assessment->decision->value,
            'target_branch' => $assessment->target_branch,
            'ticket_status_before' => $decision->ticket_status_before,
            'ticket_status_after' => $decision->ticket_status_after,
            'reason_present' => $decision->reason !== null,
            'simulated' => true,
            'actual_state' => 'unverified',
            'real_merge_performed' => false,
        ];

        $this->outbox->append(new DomainEventEnvelope(
            eventId: $eventId,
            eventName: $eventType->value,
            aggregateType: AuditSubjectType::RoadmapTask->value,
            aggregateId: (string) $ticket->id,
            organizationId: $project->organization_id,
            projectId: $project->id,
            actor: DomainEventActor::user($actor->id),
            provider: 'human',
            occurredAt: $decision->decided_at,
            correlationId: $correlationId,
            causationId: $causationId,
            executionId: $reviewExecution->id,
            schemaVersion: 1,
            payload: $metadata,
        ));

        $this->auditEvents->record(
            organizationId: $project->organization_id,
            projectId: $project->id,
            actorType: AuditActorType::User,
            actorId: (string) $actor->id,
            eventType: $eventType,
            subjectType: AuditSubjectType::RoadmapTask,
            subjectId: (string) $ticket->id,
            correlationId: $correlationId,
            metadata: $metadata,
            causationId: $causationId,
            executionId: $reviewExecution->id,
            deduplicationKey: sprintf(
                'simulated-merge-decision:%d:%s',
                $project->id,
                $idempotencyKeyHash,
            ),
        );
    }

    /**
     * Map each disposition to one stable simulation-specific event.
     */
    private function eventType(
        MergeDecisionAction $action,
    ): AuditEventType {
        return match ($action) {
            MergeDecisionAction::Approve => AuditEventType::SimulatedMergeApproved,

            MergeDecisionAction::RequestChanges => AuditEventType::SimulatedMergeChangesRequested,

            MergeDecisionAction::Escalate => AuditEventType::SimulatedMergeEscalated,

            MergeDecisionAction::Defer => AuditEventType::SimulatedMergeDeferred,
        };
    }

    /**
     * Normalize and redact optional human reasoning before persistence.
     */
    private function normalizeReason(
        ?string $reason,
    ): ?string {
        if ($reason === null || trim($reason) === '') {
            return null;
        }

        $normalized = $this->redactor->message(
            trim($reason),
        );

        if ($normalized === '') {
            return null;
        }

        if (mb_strlen($normalized) > 2_000) {
            throw new InvalidArgumentException(
                'The simulated merge decision reason may not exceed 2,000 characters.',
            );
        }

        return $normalized;
    }

    /**
     * Produce a stable hash for exact replay and payload-drift detection.
     */
    private function requestFingerprint(
        DecideSimulatedMergeCommand $command,
        ?string $reason,
    ): string {
        try {
            $material = json_encode(
                [
                    'organization_id' => $command->organizationId,
                    'project_id' => $command->projectId,
                    'qa_assessment_id' => $command->qaAssessmentId,
                    'actor_user_id' => $command->actorUserId,
                    'action' => $command->action->value,
                    'expected_assessment_fingerprint' => $command
                        ->expectedAssessmentFingerprint,
                    'reason' => $reason,
                ],
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                'The simulated merge decision must be JSON serializable.',
                previous: $exception,
            );
        }

        return hash('sha256', $material);
    }

    /**
     * Build one stable successful response for new and replayed commands.
     */
    private function successfulResult(
        MergeDecision $decision,
        bool $replayed,
    ): CommandResult {
        return CommandResult::succeeded([
            'merge_decision_id' => $decision->id,
            'project_id' => $decision->project_id,
            'roadmap_task_id' => $decision->roadmap_task_id,
            'qa_assessment_id' => $decision->qa_assessment_id,
            'action' => $decision->action->value,
            'ticket_status' => $decision->ticket_status_after,
            'simulated' => true,
            'actual_state' => 'unverified',
            'real_merge_performed' => false,
            'replayed' => $replayed,
        ]);
    }

    /**
     * Normalize one required bounded command identifier.
     */
    private function normalizeIdentifier(
        string $value,
        string $name,
        int $maximumLength,
    ): string {
        $normalized = trim($value);

        if (
            $normalized === ''
            || mb_strlen($normalized) > $maximumLength
            || preg_match(
                '/\A[A-Za-z0-9][A-Za-z0-9._:-]*\z/',
                $normalized,
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                "The {$name} identifier is invalid.",
            );
        }

        return $normalized;
    }

    /**
     * Normalize one optional bounded command identifier.
     */
    private function normalizeOptionalIdentifier(
        ?string $value,
        string $name,
        int $maximumLength,
    ): ?string {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return $this->normalizeIdentifier(
            value: $value,
            name: $name,
            maximumLength: $maximumLength,
        );
    }
}
