<?php

declare(strict_types=1);

namespace App\Application\Planning;

use App\Application\Approvals\Commands\RequestApproval;
use App\Application\Executions\Data\ExecutionAttemptContext;
use App\Application\Executions\Data\ProviderSelection;
use App\Application\Executions\ExecutionResilienceManager;
use App\Application\Planning\Data\PlanningDiagnostic;
use App\Application\Planning\Data\PlanningExecutionRequest;
use App\Application\Planning\Data\PlanningSourceReference;
use App\Application\Security\RedactSensitiveData;
use App\Application\Shared\Commands\CommandBus;
use App\Application\Workflows\Data\WorkflowTransitionContext;
use App\Application\Workflows\TransitionWorkflowInstance;
use App\Domain\Approvals\ApprovalType;
use App\Domain\Projects\Configuration\ProviderPolicy;
use App\Domain\Projects\ProjectStatus;
use App\Models\Approval;
use App\Models\Execution;
use App\Models\ExecutionAttempt;
use App\Models\Roadmap;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Throwable;

final readonly class ProcessPlanningExecution
{
    public function __construct(
        private ExecutionProviderRegistry $providers,
        private ExecutionResilienceManager $attempts,
        private PlanningResultValidator $validator,
        private PersistRoadmap $roadmaps,
        private CommandBus $commands,
        private TransitionWorkflowInstance $workflowTransitions,
        private RedactSensitiveData $redactor,
        private GrantRoadmapApprovalByPolicy $policyApproval,
        private PersistPlanningDiagnostics $diagnostics,
    ) {}

    public function handle(Execution $execution, string $scenario = 'happy_path', int $seed = 1, ?string $feedbackFingerprint = null): void
    {
        $execution->loadMissing([
            'project',
            'workflowInstance.workflowDefinition',
            'projectContextSnapshot.configurationVersion',
        ]);

        $snapshot = $execution->projectContextSnapshot;
        $configurationVersion = $snapshot?->configurationVersion;
        if (
            $snapshot === null
            || $configurationVersion === null
            || $snapshot->project_id !== $execution->project_id
            || $configurationVersion->project_id !== $execution->project_id
            || $execution->workflowInstance?->project_id !== $execution->project_id
        ) {
            throw new LogicException('Planning execution context ownership is inconsistent.');
        }

        $configuration = $configurationVersion->snapshot;
        $policy = Arr::get($configuration, 'policy');
        if (! is_array($policy)) {
            throw new LogicException('The immutable configuration policy snapshot is invalid.');
        }
        $providerPolicy = Arr::get($policy, 'provider');
        if (! is_array($providerPolicy)) {
            throw new LogicException('The immutable provider policy snapshot is invalid.');
        }

        $provider = $this->providers->resolve(ProviderPolicy::fromArray($providerPolicy), $execution->capability);
        $this->workflowTransitions->handle(
            instance: $execution->workflowInstance,
            transitionName: 'planning.start',
            context: WorkflowTransitionContext::system(
                actorId: 'planning-execution',
                correlationId: $execution->correlation_id,
                executionId: $execution->id,
            ),
        );

        $selection = ProviderSelection::fromProvider(
            requestedCapability: $execution->capability,
            provider: $provider,
            selectionSource: 'immutable_configuration_snapshot',
        );

        $attempt = $this->attempts->startAttempt(
            execution: $execution,
            context: ExecutionAttemptContext::fromProviderSelection(
                selection: $selection,
                requestedReasoningLevel: $execution
                    ->requested_reasoning_level,
                effectiveReasoningLevel: $execution
                    ->requested_reasoning_level,
                reasoningResolutionSource: 'immutable_configuration_snapshot',
                simulationScenario: $scenario,
                simulationSeed: (string) $seed,
            ),
        );

        try {
            try {
                $request = new PlanningExecutionRequest(
                    projectId: $execution->project_id,
                    contextSnapshotId: $snapshot->id,
                    contextFingerprint: $snapshot->approved_document_set_fingerprint,
                    reasoningLevel: $execution->requested_reasoning_level,
                    documents: array_map(
                        static fn (array $document): PlanningSourceReference => new PlanningSourceReference(
                            documentId: $document['document_id'],
                            documentVersionId: $document['document_version_id'],
                            version: $document['version'],
                            checksumSha256: $document['checksum_sha256'],
                        ),
                        $snapshot->approved_document_versions,
                    ),
                    scenario: $scenario,
                    seed: $seed,
                    feedbackFingerprint: $feedbackFingerprint,
                    configurationVersionId: $configurationVersion->id,
                    configurationRevision: $configurationVersion->revision,
                    configurationSchemaVersion: $configurationVersion->schema_version,
                    configuration: $configuration,
                    policies: $policy,
                    existingRoadmaps: $this->existingRoadmapState($execution),
                    providerPolicy: $providerPolicy,
                    reasoningPolicy: ['default_reasoning' => Arr::get($policy, 'default_reasoning')],
                    budgetPolicy: (array) Arr::get($policy, 'budget', []),
                    approvalPolicy: (array) Arr::get($policy, 'approval', []),
                    organizationPolicy: ['organization_id' => $execution->project->organization_id],
                    projectPolicy: ['project_id' => $execution->project_id],
                    latestRoadmap: $this->latestRoadmapState($execution),
                );
                $result = $provider->execute($request);
                $this->validator->validate($result, $request);
                $this->diagnostics->handle($execution, $attempt, $result->diagnostics);
                $roadmap = $this->roadmaps->handle($execution, $request, $result, $selection);
            } catch (InvalidArgumentException $exception) {
                $this->blockInvalidResult($execution, $attempt, $exception);

                return;
            }

            if ($roadmap->readiness === 'blocked') {
                $reason = $result->diagnostics[0]->message ?? 'Planning produced a deterministic blocked outcome.';
                $this->attempts->blockAttempt($attempt, 'planning.blocked_result', $reason);
                $execution->project->transitionTo(ProjectStatus::Blocked);
                $this->transitionWorkflowIfAvailable($execution, 'planning.block');

                return;
            }

            $approvalRequired = (bool) Arr::get($policy, 'approval.roadmap_required', true);
            $approvalAuthority = $approvalRequired ? 'human' : 'immutable_policy';
            $approvalResult = $this->commands->dispatch(new RequestApproval(
                projectId: $execution->project_id,
                type: ApprovalType::Roadmap,
                idempotencyKey: sprintf('roadmap-gate:%d:%d:%s', $roadmap->id, $roadmap->content_version, $approvalAuthority),
                correlationId: $execution->correlation_id,
                workflowInstanceId: $execution->workflow_instance_id,
                executionId: $execution->id,
                payload: $this->approvalPayload($roadmap, $approvalAuthority, $configurationVersion->id),
            ));
            if (! $approvalResult->isSuccessful()) {
                throw new LogicException('The roadmap approval gate could not be created.');
            }

            $approval = Approval::query()->whereKey($approvalResult->data['approval_id'])->firstOrFail();
            $this->transitionWorkflowIfAvailable($execution, 'planning.await_approval');
            if ($approvalRequired) {
                $roadmap->forceFill(['approval_id' => $approval->id, 'status' => 'awaiting_approval'])->save();
                $execution->project->transitionTo(ProjectStatus::AwaitingRoadmapApproval);
                $this->attempts->completeAttempt($attempt);

                return;
            }

            $this->policyApproval->handle($roadmap, $approval, $execution->correlation_id);
            $execution->project->transitionTo(ProjectStatus::ReadyForDevelopment);
            $this->transitionWorkflowIfAvailable($execution, 'roadmap.approve', ['roadmap.approved' => true]);
            $this->attempts->completeAttempt($attempt);
        } catch (Throwable $exception) {
            $this->attempts->failAttempt(
                attempt: $attempt,
                errorCode: 'planning.provider_or_infrastructure_failure',
                errorMessage: $exception->getMessage(),
                retryable: true,
            );
            throw $exception;
        }
    }

    private function blockInvalidResult(Execution $execution, ExecutionAttempt $attempt, InvalidArgumentException $exception): void
    {
        $message = $this->redactor->message($exception->getMessage());
        $code = str_contains($message, 'Dependency cycle detected')
            ? 'planning.dependency_cycle'
            : 'planning.invalid_result';
        $this->diagnostics->handle($execution, $attempt, [new PlanningDiagnostic(
            code: $code,
            category: 'deterministic_blocker',
            message: Str::limit($message, 2000, ''),
            details: ['exception_type' => $exception::class],
        )]);
        $this->attempts->blockAttempt($attempt, $code, $message);
        $execution->project->transitionTo(ProjectStatus::Blocked);
        $this->transitionWorkflowIfAvailable($execution, 'planning.block');
    }

    /** @return list<array<string, mixed>> */
    private function existingRoadmapState(Execution $execution): array
    {
        return array_values(Roadmap::query()
            ->where('project_id', $execution->project_id)
            ->orderBy('revision')
            ->get(['id', 'revision', 'content_version', 'status', 'readiness', 'candidate_fingerprint'])
            ->map(static fn (Roadmap $roadmap): array => $roadmap->only([
                'id',
                'revision',
                'content_version',
                'status',
                'readiness',
                'candidate_fingerprint',
            ]))
            ->values()
            ->all());
    }

    /** @return array<string, mixed>|null */
    private function latestRoadmapState(Execution $execution): ?array
    {
        $roadmap = Roadmap::query()
            ->with('tasks:id,roadmap_id,stable_id,title,priority,risk,logical_agent,estimated_complexity')
            ->where('project_id', $execution->project_id)
            ->orderByDesc('revision')
            ->first();
        if ($roadmap === null) {
            return null;
        }

        return [
            ...$roadmap->only(['id', 'revision', 'content_version', 'status', 'readiness', 'candidate_fingerprint']),
            'tasks' => $roadmap->tasks->map(static fn ($task): array => $task->only([
                'stable_id',
                'title',
                'priority',
                'risk',
                'logical_agent',
                'estimated_complexity',
            ]))->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function approvalPayload(Roadmap $roadmap, string $authority, int $configurationVersionId): array
    {
        return [
            'approval_authority' => $authority,
            'roadmap_id' => $roadmap->id,
            'revision' => $roadmap->revision,
            'content_version' => $roadmap->content_version,
            'candidate_fingerprint' => $roadmap->candidate_fingerprint,
            'output_fingerprint' => $roadmap->output_fingerprint,
            'project_context_snapshot_id' => $roadmap->project_context_snapshot_id,
            'planning_execution_id' => $roadmap->planning_execution_id,
            'configuration_version_id' => $configurationVersionId,
        ];
    }

    /** @param array<string, mixed> $guardContext */
    private function transitionWorkflowIfAvailable(Execution $execution, string $transition, array $guardContext = []): void
    {
        $definition = $execution->workflowInstance->workflowDefinition->definition;
        $available = collect($definition['transitions'])->contains(
            static fn (array $candidate): bool => $candidate['name'] === $transition,
        );
        if (! $available) {
            return;
        }

        $this->workflowTransitions->handle(
            instance: $execution->workflowInstance,
            transitionName: $transition,
            context: WorkflowTransitionContext::system(
                actorId: 'planning-execution',
                correlationId: $execution->correlation_id,
                executionId: $execution->id,
                guardContext: $guardContext,
            ),
        );
    }
}
