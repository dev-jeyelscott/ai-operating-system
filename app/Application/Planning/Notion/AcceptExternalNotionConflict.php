<?php

declare(strict_types=1);

namespace App\Application\Planning\Notion;

use App\Application\Approvals\Commands\RequestApproval;
use App\Application\Audit\RecordAuditEvent;
use App\Application\Integrations\Contracts\IntegrationCredentialCipher;
use App\Application\Integrations\Contracts\NotionPublicationClient;
use App\Application\Planning\MaterializeRoadmap;
use App\Application\Planning\RoadmapCommandFingerprint;
use App\Application\Shared\Commands\CommandBus;
use App\Application\Shared\Exceptions\ConflictException;
use App\Application\Workflows\CreateWorkflowInstance;
use App\Application\Workflows\Data\WorkflowTransitionContext;
use App\Application\Workflows\TransitionWorkflowInstance;
use App\Domain\Approvals\ApprovalType;
use App\Domain\Audit\AuditActorType;
use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\AuditSubjectType;
use App\Domain\Integrations\IntegrationProvider;
use App\Domain\Integrations\NotionConnectionStatus;
use App\Domain\Projects\ProjectStatus;
use App\Models\Approval;
use App\Models\Execution;
use App\Models\ExternalTicketMapping;
use App\Models\NotionReconciliationConflict;
use App\Models\Project;
use App\Models\ProjectIntegration;
use App\Models\ProviderCredential;
use App\Models\Roadmap;
use App\Models\RoadmapMilestone;
use App\Models\RoadmapPhase;
use App\Models\RoadmapTask;
use App\Models\RoadmapTraceabilityLink;
use App\Models\TaskDependency;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/** Accepts a tracked Notion edit only by creating a separately approvable revision. */
final readonly class AcceptExternalNotionConflict
{
    public function __construct(
        private NotionPublicationClient $client,
        private IntegrationCredentialCipher $cipher,
        private NotionExternalTicketDecoder $decoder,
        private NotionTicketFingerprint $fingerprints,
        private MaterializeRoadmap $materializer,
        private CreateWorkflowInstance $workflows,
        private TransitionWorkflowInstance $workflowTransitions,
        private CommandBus $commands,
        private RecordAuditEvent $audit,
    ) {}

    public function handle(NotionReconciliationConflict $conflict, User $actor, string $expectedFingerprint, string $reason, string $idempotencyKey, string $correlationId): Roadmap
    {
        $normalizedReason = trim($reason);
        if ($normalizedReason === '') {
            throw new InvalidArgumentException('A reason is required when accepting external Notion content.');
        }

        $replay = NotionReconciliationConflict::query()
            ->with('mapping.task.roadmap.project')
            ->findOrFail($conflict->id);
        if ($replay->state === 'accepted' && $replay->decision === 'accept_external' && $replay->resulting_roadmap_id !== null) {
            Gate::forUser($actor)->authorize('approve', $replay->mapping->task->roadmap->project);
            if (! hash_equals($replay->current_fingerprint, $expectedFingerprint)) {
                throw new ConflictException('The Notion reconciliation conflict is stale.');
            }

            return Roadmap::query()->findOrFail($replay->resulting_roadmap_id);
        }

        [$mapping, $task, $integration, $credential] = $this->resolutionContext($conflict, $actor, $expectedFingerprint);
        $page = $this->client->retrievePage($this->cipher->decrypt($credential->secret_ciphertext), (string) $mapping->page_id);
        if ($page->dataSourceId !== $integration->data_source_id) {
            throw new ConflictException('The external page is no longer in the configured Notion data source.');
        }
        $body = $this->client->retrievePageBody($this->cipher->decrypt($credential->secret_ciphertext), (string) $mapping->page_id);
        if (! hash_equals($conflict->external_fingerprint ?? '', $this->fingerprints->from($page->properties, $body))) {
            throw new ConflictException('The external Notion page changed after reconciliation.');
        }
        $patch = $this->decoder->decode($task, $page->properties, $body);

        return DB::transaction(function () use ($conflict, $actor, $expectedFingerprint, $normalizedReason, $idempotencyKey, $correlationId, $patch): Roadmap {
            $lockedConflict = NotionReconciliationConflict::query()->lockForUpdate()->findOrFail($conflict->id);
            if ($lockedConflict->state === 'accepted' && $lockedConflict->decision === 'accept_external' && $lockedConflict->resulting_roadmap_id !== null) {
                return Roadmap::query()->findOrFail($lockedConflict->resulting_roadmap_id);
            }
            if ($lockedConflict->state !== 'open' || ! hash_equals($lockedConflict->current_fingerprint, $expectedFingerprint)) {
                throw new ConflictException('The Notion reconciliation conflict is stale.');
            }

            $mapping = ExternalTicketMapping::query()->with('task.roadmap.project')->lockForUpdate()->findOrFail($lockedConflict->external_ticket_mapping_id);
            $source = $mapping->task->roadmap;
            $project = Project::query()->whereKey($source->project_id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('approve', $project);
            if ($lockedConflict->organization_id !== $project->organization_id || $lockedConflict->project_id !== $project->id || $source->status !== 'approved') {
                throw new ConflictException('The Notion conflict no longer belongs to an approved roadmap.');
            }
            if (Roadmap::query()->where('project_id', $project->id)->orderByDesc('revision')->value('id') !== $source->id) {
                throw new ConflictException('The approved roadmap is no longer the current revision.');
            }
            if ($project->status !== ProjectStatus::ReadyForDevelopment) {
                throw new ConflictException('External roadmap acceptance requires a project ready for development.');
            }
            if (! hash_equals((string) $mapping->reconciliation_fingerprint, $expectedFingerprint)) {
                throw new ConflictException('The Notion mapping changed after reconciliation.');
            }

            $source->load(['execution.workflowInstance.workflowDefinition', 'phases', 'milestones', 'tasks.dependencies', 'tasks.traceabilityLinks']);
            $candidate = $this->materializer->apply($this->materializer->handle($source), ['tasks' => [$mapping->task->stable_id => $patch]]);
            $candidateFingerprint = RoadmapCommandFingerprint::make($candidate);
            $workflow = $this->workflows->handle($project, $source->execution->workflowInstance->workflowDefinition->definition_key, $source->execution->workflowInstance->workflowDefinition->version);
            $execution = $this->execution($source->execution, $workflow->id, $correlationId, $idempotencyKey, $lockedConflict);
            $this->workflowTransitions->handle(
                instance: $workflow,
                transitionName: 'planning.start',
                context: WorkflowTransitionContext::user(
                    userId: $actor->id,
                    correlationId: $correlationId,
                    executionId: $execution->id,
                ),
            );
            $this->workflowTransitions->handle(
                instance: $workflow,
                transitionName: 'planning.await_approval',
                context: WorkflowTransitionContext::user(
                    userId: $actor->id,
                    correlationId: $correlationId,
                    executionId: $execution->id,
                ),
            );
            $roadmap = $this->createRoadmap($source, $execution, $candidate, $candidateFingerprint, $lockedConflict);
            $this->copyStructure($source, $roadmap, $mapping->task->stable_id, $patch);

            $approvalResult = $this->commands->dispatch(new RequestApproval(
                projectId: $project->id,
                type: ApprovalType::Roadmap,
                idempotencyKey: sprintf('notion-conflict:%d:%s', $lockedConflict->id, hash('sha256', $idempotencyKey)),
                correlationId: $correlationId,
                workflowInstanceId: $workflow->id,
                executionId: $execution->id,
                requestedByUserId: $actor->id,
                payload: ['approval_authority' => 'human', 'roadmap_id' => $roadmap->id, 'revision' => $roadmap->revision, 'content_version' => 1, 'candidate_fingerprint' => $candidateFingerprint, 'output_fingerprint' => $candidateFingerprint, 'project_context_snapshot_id' => $roadmap->project_context_snapshot_id, 'planning_execution_id' => $execution->id],
            ));
            if (! $approvalResult->isSuccessful()) {
                throw new InvalidArgumentException('The accepted external revision approval could not be created.');
            }
            $approvalId = $approvalResult->data['approval_id'] ?? null;
            if (! is_int($approvalId)) {
                throw new InvalidArgumentException('The accepted external revision approval could not be resolved.');
            }
            $approval = Approval::query()->whereKey($approvalId)->firstOrFail();
            $roadmap->forceFill(['approval_id' => $approval->id, 'status' => 'awaiting_approval'])->save();
            $project->transitionTo(ProjectStatus::Planning);
            $project->transitionTo(ProjectStatus::AwaitingRoadmapApproval);
            $lockedConflict->update(['state' => 'accepted', 'decision' => 'accept_external', 'decision_reason' => $normalizedReason, 'decided_by_user_id' => $actor->id, 'decided_at' => now(), 'resulting_roadmap_id' => $roadmap->id]);
            $mapping->update(['reconciliation_state' => 'accepted_external']);
            $this->audit->record(organizationId: $project->organization_id, projectId: $project->id, actorType: AuditActorType::User, actorId: (string) $actor->id, eventType: AuditEventType::NotionConflictAccepted, subjectType: AuditSubjectType::ExternalTicketMapping, subjectId: (string) $mapping->id, correlationId: $correlationId, metadata: ['conflict_id' => $lockedConflict->id, 'before_fingerprint' => $lockedConflict->published_fingerprint, 'external_fingerprint' => $lockedConflict->external_fingerprint, 'resulting_roadmap_id' => $roadmap->id, 'resulting_fingerprint' => $candidateFingerprint]);

            return $roadmap;
        }, attempts: 3);
    }

    /** @return array{ExternalTicketMapping, RoadmapTask, ProjectIntegration, ProviderCredential} */
    private function resolutionContext(NotionReconciliationConflict $conflict, User $actor, string $expectedFingerprint): array
    {
        $conflict = NotionReconciliationConflict::query()->with('mapping.task.roadmap.project')->findOrFail($conflict->id);
        $mapping = $conflict->mapping;
        $task = $mapping->task;
        Gate::forUser($actor)->authorize('approve', $task->roadmap->project);
        if ($conflict->state !== 'open' || ! hash_equals($conflict->current_fingerprint, $expectedFingerprint) || $mapping->page_id === null) {
            throw new ConflictException('The Notion reconciliation conflict is stale.');
        }
        $integration = ProjectIntegration::query()->forOrganization($conflict->organization_id)->forProject($conflict->project_id)->where('provider', IntegrationProvider::Notion->value)->first();
        $credential = ProviderCredential::query()->forOrganization($conflict->organization_id)->forProject($conflict->project_id)->where('provider', IntegrationProvider::Notion->value)->first();
        if ($integration === null || $credential === null || $integration->connection_status !== NotionConnectionStatus::Connected || $integration->data_source_id === null || $integration->verified_credential_version !== $credential->version) {
            throw new ConflictException('The verified Notion connection is not ready for conflict resolution.');
        }

        return [$mapping, $task, $integration, $credential];
    }

    private function execution(Execution $source, int $workflowId, string $correlationId, string $idempotencyKey, NotionReconciliationConflict $conflict): Execution
    {
        return Execution::query()->create(['project_id' => $source->project_id, 'workflow_instance_id' => $workflowId, 'project_context_snapshot_id' => $source->project_context_snapshot_id, 'capability' => $source->capability, 'logical_role' => $source->logical_role, 'requested_reasoning_level' => $source->requested_reasoning_level, 'retry_limit' => $source->retry_limit, 'timeout_seconds' => $source->timeout_seconds, 'retry_base_delay_seconds' => $source->retry_base_delay_seconds, 'retry_max_delay_seconds' => $source->retry_max_delay_seconds, 'retry_jitter_percent' => $source->retry_jitter_percent, 'correlation_id' => $correlationId, 'idempotency_key' => hash('sha256', implode('|', ['notion-conflict', $conflict->id, $conflict->current_fingerprint, hash('sha256', $idempotencyKey)]))]);
    }

    /** @param array<string, mixed> $candidate */
    private function createRoadmap(Roadmap $source, Execution $execution, array $candidate, string $fingerprint, NotionReconciliationConflict $conflict): Roadmap
    {
        return Roadmap::query()->create(['project_id' => $source->project_id, 'planning_execution_id' => $execution->id, 'project_context_snapshot_id' => $source->project_context_snapshot_id, 'parent_roadmap_id' => $source->id, 'schema_version' => $source->schema_version, 'revision' => $source->revision + 1, 'content_version' => 1, 'provider_id' => $source->provider_id, 'scenario' => $source->scenario, 'seed' => $source->seed, 'input_fingerprint' => RoadmapCommandFingerprint::make(['source_roadmap_id' => $source->id, 'conflict_id' => $conflict->id, 'external_fingerprint' => $conflict->external_fingerprint]), 'output_fingerprint' => $fingerprint, 'candidate_fingerprint' => $fingerprint, 'status' => 'generated', 'readiness' => $source->readiness, 'goal' => $candidate['goal'], 'scope' => $candidate['scope'], 'assumptions' => $candidate['assumptions'], 'constraints' => $candidate['constraints'], 'definition_of_done' => $candidate['definition_of_done'], 'required_approvals' => $candidate['required_approvals'], 'document_inventory' => $candidate['document_inventory'], 'document_summary' => $candidate['document_summary'], 'architecture_concerns' => $candidate['architecture_concerns'], 'security_concerns' => $candidate['security_concerns'], 'readiness_reasons' => $source->readiness_reasons, 'metadata' => array_merge($source->metadata ?? [], ['accepted_external_notion_conflict_id' => $conflict->id, 'external_fingerprint' => $conflict->external_fingerprint]), 'derived_graph' => $source->derived_graph, 'generated_snapshot' => $candidate, 'feedback_fingerprint' => $source->feedback_fingerprint, 'generated_at' => now()]);
    }

    /** @param array<string, mixed> $patch */
    private function copyStructure(Roadmap $source, Roadmap $target, string $acceptedTaskStableId, array $patch): void
    {
        $phases = [];
        foreach ($source->phases as $phase) {
            $phases[$phase->id] = RoadmapPhase::query()->create(['roadmap_id' => $target->id, 'stable_id' => $phase->stable_id, 'name' => $phase->name, 'position' => $phase->position]);
        }
        $milestones = [];
        foreach ($source->milestones as $milestone) {
            $phase = $phases[$milestone->roadmap_phase_id] ?? null;
            if ($phase === null) {
                throw new ConflictException('The source roadmap milestone has no matching phase.');
            }
            $milestones[$milestone->id] = RoadmapMilestone::query()->create(['roadmap_id' => $target->id, 'roadmap_phase_id' => $phase->id, 'stable_id' => $milestone->stable_id, 'name' => $milestone->name, 'position' => $milestone->position]);
        }
        $tasks = [];
        foreach ($source->tasks as $task) {
            $values = ['title' => $task->title, 'objective' => $task->objective, 'ticket_type' => $task->ticket_type, 'scope' => $task->scope, 'acceptance_criteria' => $task->acceptance_criteria, 'evidence_requirements' => $task->evidence_requirements, 'priority' => $task->priority, 'risk' => $task->risk, 'estimated_complexity' => $task->estimated_complexity, 'human_approval_required' => $task->human_approval_required, 'notion_body_overrides' => $task->notion_body_overrides];
            if ($task->stable_id === $acceptedTaskStableId) {
                $values = array_merge($values, $patch);
                $values['acceptance_criteria'] = $this->criteria($task->acceptance_criteria, $patch['acceptance_criteria']);
            }
            $phase = $phases[$task->roadmap_phase_id] ?? null;
            $milestone = $milestones[$task->roadmap_milestone_id] ?? null;
            if ($phase === null || $milestone === null) {
                throw new ConflictException('The source roadmap task has no matching structure.');
            }
            $tasks[$task->id] = RoadmapTask::query()->create(['roadmap_id' => $target->id, 'roadmap_phase_id' => $phase->id, 'roadmap_milestone_id' => $milestone->id, 'stable_id' => $task->stable_id, ...$values, 'source_references' => $task->source_references, 'reasoning_level' => $task->reasoning_level, 'reasoning' => $task->reasoning, 'logical_agent' => $task->logical_agent, 'position' => $task->position, 'critical_path_rank' => $task->critical_path_rank, 'critical_path_position' => $task->critical_path_position, 'is_critical_path' => $task->is_critical_path]);
            foreach ($task->traceabilityLinks as $link) {
                RoadmapTraceabilityLink::query()->create(['roadmap_id' => $target->id, 'roadmap_task_id' => $tasks[$task->id]->id, 'criterion_stable_id' => $link->criterion_stable_id, 'project_context_snapshot_id' => $link->project_context_snapshot_id, 'document_id' => $link->document_id, 'document_version_id' => $link->document_version_id, 'document_version' => $link->document_version, 'checksum_sha256' => $link->checksum_sha256]);
            }
        }
        foreach ($source->tasks as $task) {
            foreach ($task->dependencies as $dependency) {
                TaskDependency::query()->create(['roadmap_id' => $target->id, 'roadmap_task_id' => $tasks[$task->id]->id, 'depends_on_task_id' => $tasks[$dependency->depends_on_task_id]->id]);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $criteria
     * @param  array<string, string>  $descriptions
     * @return list<array<string, mixed>>
     */
    private function criteria(array $criteria, array $descriptions): array
    {
        foreach ($criteria as $index => $criterion) {
            if (isset($criterion['stable_id'], $descriptions[$criterion['stable_id']])) {
                $criteria[$index]['description'] = $descriptions[$criterion['stable_id']];
            }
        }

        return $criteria;
    }
}
