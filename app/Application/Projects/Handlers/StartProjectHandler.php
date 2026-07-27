<?php

declare(strict_types=1);

namespace App\Application\Projects\Handlers;

use App\Application\Audit\Data\AuditContext;
use App\Application\Audit\RecordAuditEvent;
use App\Application\Events\Contracts\DomainEventOutbox;
use App\Application\Events\CreateDomainEventEnvelope;
use App\Application\Events\TransactionalOutbox;
use App\Application\Projects\Commands\StartProject;
use App\Application\Projects\CreateProjectContextSnapshot;
use App\Application\Projects\GetStartProjectPreflight;
use App\Application\Projects\StartProjectContextFingerprint;
use App\Application\Shared\Commands\CommandResult;
use App\Application\Workflows\CreateWorkflowInstance;
use App\Domain\Audit\AuditActorType;
use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\AuditSubjectType;
use App\Domain\Events\DomainEventActor;
use App\Domain\Events\DomainEventEnvelope;
use App\Domain\Projects\Events\ProjectContextSnapshotted;
use App\Domain\Projects\Events\ProjectStartRequested;
use App\Domain\Projects\ProjectStatus;
use App\Models\Execution;
use App\Models\NotificationEvent;
use App\Models\NotificationRecipient;
use App\Models\Project;
use App\Models\ProjectConfiguration;
use App\Models\ProjectContextSnapshot;
use App\Models\User;
use App\Models\WorkflowInstance;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use LogicException;

/**
 * Starts one project planning workflow for one immutable project context.
 */
final readonly class StartProjectHandler
{
    private const WORKFLOW_DEFINITION_KEY = 'project_delivery';

    private const PLANNING_CAPABILITY = 'planning.roadmap';

    private const PLANNING_LOGICAL_ROLE = 'project_manager';

    /**
     * Inject all existing deterministic application services.
     */
    public function __construct(
        private TransactionalOutbox $transactions,
        private GetStartProjectPreflight $preflight,
        private StartProjectContextFingerprint $fingerprints,
        private CreateProjectContextSnapshot $snapshots,
        private CreateWorkflowInstance $workflows,
        private CreateDomainEventEnvelope $eventEnvelopes,
        private RecordAuditEvent $auditEvents,
    ) {}

    /**
     * Authorize, revalidate, and persist StartProject atomically.
     */
    public function handle(
        StartProject $command,
    ): CommandResult {
        return $this->transactions->run(
            function (
                DomainEventOutbox $outbox,
            ) use (
                $command,
            ): CommandResult {
                $project = Project::query()
                    ->forOrganization($command->organizationId)
                    ->whereKey($command->projectId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $requester = User::query()->findOrFail(
                    $command->requestedByUserId,
                );

                Gate::forUser($requester)->authorize(
                    'approve',
                    $project,
                );

                /*
                 * This durable execution lookup closes the crash window between
                 * committing business state and completing the outer
                 * idempotency-key record.
                 */
                $existingExecution = Execution::query()
                    ->forProject($project->id)
                    ->where(
                        'idempotency_key',
                        $command->persistedIdempotencyHash(),
                    )
                    ->with([
                        'projectContextSnapshot',
                        'workflowInstance.workflowDefinition',
                    ])
                    ->first();

                if ($existingExecution !== null) {
                    return $this->existingExecutionResult(
                        command: $command,
                        execution: $existingExecution,
                    );
                }

                /*
                 * Re-run every race-sensitive preflight condition while the
                 * project row remains locked.
                 */
                $preflight = $this->preflight->handle(
                    organizationId: $command->organizationId,
                    projectId: $project->id,
                );

                $currentContextFingerprint =
                    $this->fingerprints->fromPreflight(
                        $preflight,
                    );

                if (
                    ! hash_equals(
                        $command->contextFingerprint,
                        $currentContextFingerprint,
                    )
                ) {
                    return CommandResult::conflict(
                        message: 'The project context changed after StartProject was prepared.',
                        details: [
                            'reason' => 'project_context_changed',
                            'project_id' => $project->id,
                        ],
                    );
                }

                if (! $preflight->canStart) {
                    return CommandResult::validationFailed(
                        fieldErrors: $this->preflightErrors(
                            $preflight->blockers,
                        ),
                        message: 'The project is not ready to start.',
                    );
                }

                $configuration = ProjectConfiguration::query()
                    ->where('project_id', $project->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $snapshot = $this->snapshots->handle(
                    organizationId: $command->organizationId,
                    projectId: $project->id,
                    auditContext: AuditContext::user(
                        userId: $command->requestedByUserId,
                        correlationId: $command->correlationId,
                        causationId: $command->causationId,
                    ),
                );

                if (
                    ! hash_equals(
                        $command->contextFingerprint,
                        $this->fingerprints->fromSnapshot(
                            $snapshot,
                        ),
                    )
                ) {
                    throw new LogicException(
                        'The created project context snapshot does not match the authorized StartProject context.',
                    );
                }

                $workflow = $this->workflows->handle(
                    project: $project,
                    definitionKey: self::WORKFLOW_DEFINITION_KEY,
                );

                $execution = Execution::query()->create([
                    'project_id' => $project->id,
                    'workflow_instance_id' => $workflow->id,
                    'project_context_snapshot_id' => $snapshot->id,
                    'capability' => self::PLANNING_CAPABILITY,
                    'logical_role' => self::PLANNING_LOGICAL_ROLE,
                    'requested_reasoning_level' => $configuration->default_reasoning,
                    'retry_limit' => $configuration->automatic_retry_limit,
                    'timeout_seconds' => (int) config(
                        'executions.timeout_seconds',
                        900,
                    ),
                    'retry_base_delay_seconds' => (int) config(
                        'executions.retry.base_delay_seconds',
                        30,
                    ),
                    'retry_max_delay_seconds' => (int) config(
                        'executions.retry.max_delay_seconds',
                        900,
                    ),
                    'retry_jitter_percent' => (int) config(
                        'executions.retry.jitter_percent',
                        20,
                    ),
                    'correlation_id' => $command->correlationId,
                    'idempotency_key' => $command->persistedIdempotencyHash(),
                ]);

                /*
                 * Creating the queued planning execution is the deterministic
                 * beginning of Layer 1. Provider dispatch belongs to AIOS-070.
                 */
                $project->transitionTo(
                    ProjectStatus::Planning,
                );

                $contextEvent = null;

                if ($snapshot->wasRecentlyCreated) {
                    $contextEvent =
                        $this->contextSnapshottedEvent(
                            command: $command,
                            project: $project,
                            snapshot: $snapshot,
                            execution: $execution,
                        );

                    $outbox->append($contextEvent);
                }

                $startEvent = $this->startRequestedEvent(
                    command: $command,
                    project: $project,
                    snapshot: $snapshot,
                    workflow: $workflow,
                    execution: $execution,
                    causationId: $contextEvent->eventId
                        ?? $command->causationId,
                );

                $outbox->append($startEvent);

                $this->recordStartAudit(
                    command: $command,
                    project: $project,
                    snapshot: $snapshot,
                    workflow: $workflow,
                    execution: $execution,
                    causationId: $contextEvent->eventId
                        ?? $command->causationId,
                );

                $this->notifyRequester(
                    requester: $requester,
                    project: $project,
                    snapshot: $snapshot,
                    workflow: $workflow,
                    execution: $execution,
                    sourceEventId: $startEvent->eventId,
                    occurredAt: $startEvent->occurredAt,
                );

                return $this->successfulResult(
                    execution: $execution,
                    snapshot: $snapshot,
                    workflow: $workflow,
                );
            },
        );
    }

    /**
     * Return the already committed execution after a crash-window replay.
     */
    private function existingExecutionResult(
        StartProject $command,
        Execution $execution,
    ): CommandResult {
        $snapshot = $execution->projectContextSnapshot;
        $workflow = $execution->workflowInstance;

        if (
            ! $snapshot instanceof ProjectContextSnapshot
            || ! $workflow instanceof WorkflowInstance
        ) {
            throw new LogicException(
                'An existing StartProject execution is missing immutable workflow context.',
            );
        }

        if (
            ! hash_equals(
                $command->contextFingerprint,
                $this->fingerprints->fromSnapshot($snapshot),
            )
        ) {
            return CommandResult::conflict(
                message: 'The idempotency key already belongs to a different project context.',
                details: [
                    'reason' => 'idempotency_context_mismatch',
                    'execution_id' => $execution->id,
                ],
            );
        }

        return $this->successfulResult(
            execution: $execution,
            snapshot: $snapshot,
            workflow: $workflow,
        );
    }

    /**
     * Convert ordered preflight blockers into command validation errors.
     *
     * @param  list<array{
     *     key: string,
     *     category: string,
     *     message: string,
     *     remediation: string
     * }>  $blockers
     * @return array<string, array<int, string>>
     */
    private function preflightErrors(
        array $blockers,
    ): array {
        $errors = [];

        foreach ($blockers as $blocker) {
            $field = sprintf(
                'preflight.%s',
                $blocker['key'],
            );

            $errors[$field][] = sprintf(
                '%s %s',
                $blocker['message'],
                $blocker['remediation'],
            );
        }

        return $errors;
    }

    /**
     * Build the context-snapshot domain-event envelope.
     */
    private function contextSnapshottedEvent(
        StartProject $command,
        Project $project,
        ProjectContextSnapshot $snapshot,
        Execution $execution,
    ): DomainEventEnvelope {
        return $this->eventEnvelopes->create(
            event: new ProjectContextSnapshotted(
                projectId: $project->id,
                projectContextSnapshotId: $snapshot->id,
                configurationVersionId: $snapshot->project_configuration_version_id,
                configurationRevision: $snapshot->configuration_revision,
                approvedDocumentSetFingerprint: $snapshot->approved_document_set_fingerprint,
            ),
            aggregateType: 'project_context_snapshot',
            aggregateId: (string) $snapshot->id,
            organizationId: $command->organizationId,
            projectId: $project->id,
            actor: DomainEventActor::user(
                $command->requestedByUserId,
            ),
            correlationId: $command->correlationId,
            causationId: $command->causationId,
            executionId: $execution->id,
        );
    }

    /**
     * Build the accepted StartProject domain-event envelope.
     */
    private function startRequestedEvent(
        StartProject $command,
        Project $project,
        ProjectContextSnapshot $snapshot,
        WorkflowInstance $workflow,
        Execution $execution,
        ?string $causationId,
    ): DomainEventEnvelope {
        $definition = $workflow->workflowDefinition;

        return $this->eventEnvelopes->create(
            event: new ProjectStartRequested(
                projectId: $project->id,
                projectContextSnapshotId: $snapshot->id,
                workflowInstanceId: $workflow->id,
                workflowDefinitionId: $definition->id,
                workflowDefinitionVersion: $definition->version,
                executionId: $execution->id,
                capability: $execution->capability,
            ),
            aggregateType: 'project',
            aggregateId: (string) $project->id,
            organizationId: $command->organizationId,
            projectId: $project->id,
            actor: DomainEventActor::user(
                $command->requestedByUserId,
            ),
            correlationId: $command->correlationId,
            causationId: $causationId,
            executionId: $execution->id,
        );
    }

    /**
     * Append the authoritative StartProject audit event.
     */
    private function recordStartAudit(
        StartProject $command,
        Project $project,
        ProjectContextSnapshot $snapshot,
        WorkflowInstance $workflow,
        Execution $execution,
        ?string $causationId,
    ): void {
        $this->auditEvents->record(
            organizationId: $command->organizationId,
            projectId: $project->id,
            actorType: AuditActorType::User,
            actorId: (string) $command->requestedByUserId,
            eventType: AuditEventType::ProjectStartRequested,
            subjectType: AuditSubjectType::Execution,
            subjectId: $execution->id,
            correlationId: $command->correlationId,
            metadata: [
                'project_context_snapshot_id' => $snapshot->id,
                'workflow_instance_id' => $workflow->id,
                'capability' => $execution->capability,
            ],
            causationId: $causationId,
            executionId: $execution->id,
            deduplicationKey: sprintf(
                'project-start-requested:%s',
                $execution->id,
            ),
        );
    }

    /**
     * Create one sanitized notification for the requesting user.
     */
    private function notifyRequester(
        User $requester,
        Project $project,
        ProjectContextSnapshot $snapshot,
        WorkflowInstance $workflow,
        Execution $execution,
        string $sourceEventId,
        CarbonImmutable $occurredAt,
    ): void {
        $notification = new NotificationEvent;

        $notification->forceFill([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'source_event_id' => $sourceEventId,
            'event_name' => 'project.start_requested',
            'title' => 'Project planning started',
            'message' => sprintf(
                'Planning has been queued for %s.',
                $project->name,
            ),
            'action_url' => null,
            'data' => [
                'project_context_snapshot_id' => $snapshot->id,
                'workflow_instance_id' => $workflow->id,
                'execution_id' => $execution->id,
                'execution_status' => $execution->status->value,
            ],
            'correlation_id' => $execution->correlation_id,
            'execution_id' => $execution->id,
            'occurred_at' => $occurredAt,
            'created_at' => now(),
        ])->save();

        $recipient = new NotificationRecipient;

        $recipient->forceFill([
            'notification_event_id' => $notification->id,
            'organization_id' => $project->organization_id,
            'recipient_user_id' => $requester->id,
            'created_at' => now(),
        ])->save();
    }

    /**
     * Return the stable StartProject result.
     */
    private function successfulResult(
        Execution $execution,
        ProjectContextSnapshot $snapshot,
        WorkflowInstance $workflow,
    ): CommandResult {
        $workflow->loadMissing('workflowDefinition');

        return CommandResult::succeeded([
            'project_id' => $execution->project_id,
            'project_context_snapshot_id' => $snapshot->id,
            'workflow_instance_id' => $workflow->id,
            'workflow_definition_id' => $workflow->workflow_definition_id,
            'workflow_definition_version' => $workflow->workflowDefinition->version,
            'execution_id' => $execution->id,
            'execution_status' => $execution->status->value,
            'correlation_id' => $execution->correlation_id,
        ]);
    }
}
