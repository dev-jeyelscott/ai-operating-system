<?php

declare(strict_types=1);

namespace App\Application\Development;

use App\Application\Development\Data\DevelopmentExecutionRequest;
use App\Application\Development\Data\DevelopmentExecutionResult;
use App\Application\Executions\Data\ExecutionAttemptContext;
use App\Application\Executions\ExecutionResilienceManager;
use App\Application\Security\RedactSensitiveData;
use App\Application\Tickets\TicketLeaseManager;
use App\Application\Tickets\TransitionTicketStatus;
use App\Domain\Audit\AuditEventType;
use App\Domain\Development\DevelopmentExecutionOutcome;
use App\Domain\Development\Exceptions\DevelopmentProviderTimeout;
use App\Domain\Executions\ExecutionStatus;
use App\Domain\Tickets\TicketLeaseReleaseReason;
use App\Domain\Tickets\TicketStatus;
use App\Models\Execution;
use App\Models\ExecutionAttempt;
use App\Models\Project;
use App\Models\Roadmap;
use App\Models\RoadmapTask;
use App\Models\TicketExecutionLease;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final readonly class ProcessDevelopmentExecution
{
    public function __construct(
        private DevelopmentProviderRegistry $providers,
        private DevelopmentResultValidator $validator,
        private DevelopmentArtifactRecorder $artifacts,
        private ExecutionResilienceManager $attempts,
        private TransitionTicketStatus $tickets,
        private TicketLeaseManager $leases,
        private RecordDevelopmentLifecycleEvent $events,
        private RedactSensitiveData $redactor,
    ) {}

    public function handle(Execution $execution, string $scenario = 'happy_path', int $seed = 1): void
    {
        $started = DB::transaction(function () use ($execution, $seed): array {
            $leaseIdentity = TicketExecutionLease::query()
                ->where('execution_id', $execution->id)
                ->active()
                ->firstOrFail(['id', 'project_id', 'roadmap_task_id']);
            $ticketIdentity = RoadmapTask::query()->whereKey($leaseIdentity->roadmap_task_id)->firstOrFail(['id', 'roadmap_id']);
            $project = Project::query()
                ->forOrganization($execution->project->organization_id)
                ->whereKey($execution->project_id)
                ->lock('for share')
                ->firstOrFail();
            $lockedExecution = Execution::query()->forProject($project->id)->whereKey($execution->id)->lockForUpdate()->firstOrFail();

            if ($lockedExecution->capability !== 'development.simulation' || $lockedExecution->status !== ExecutionStatus::Queued || $lockedExecution->cancel_requested_at !== null) {
                throw new \LogicException('Development execution cannot start.');
            }

            $roadmap = Roadmap::query()->whereKey($ticketIdentity->roadmap_id)->where('project_id', $project->id)->where('project_context_snapshot_id', $lockedExecution->project_context_snapshot_id)->lock('for share')->firstOrFail();
            $ticket = RoadmapTask::query()->whereKey($ticketIdentity->id)->where('roadmap_id', $roadmap->id)->lockForUpdate()->firstOrFail();
            $lease = TicketExecutionLease::query()->whereKey($leaseIdentity->id)->where('project_id', $project->id)->where('execution_id', $lockedExecution->id)->where('roadmap_task_id', $ticket->id)->active()->lockForUpdate()->firstOrFail();
            $lockedExecution->setRelation('project', $project);

            $isRetry = $ticket->status === TicketStatus::InProgress && $lockedExecution->attempt_count > 0;
            if ($lease->project_id !== $lockedExecution->project_id || $lease->roadmap_task_id !== $ticket->id || (! $isRetry && ! in_array($ticket->status, [TicketStatus::Ready, TicketStatus::ChangesRequested], true))) {
                throw new \LogicException('Development execution lineage is invalid.');
            }

            $attempt = $this->attempts->startAttempt($lockedExecution, new ExecutionAttemptContext(
                executionProvider: 'simulation', modelIdentifier: null,
                requestedReasoningLevel: $lockedExecution->requested_reasoning_level,
                effectiveReasoningLevel: $lockedExecution->requested_reasoning_level,
                reasoningResolutionSource: 'immutable_configuration_snapshot', simulationMode: 'simulated', simulationSeed: (string) $seed,
            ));
            if (! $isRetry) {
                $ticket = $this->tickets->handleLocked(
                    project: $project, roadmap: $roadmap, ticket: $ticket, target: TicketStatus::InProgress,
                    idempotencyKey: "development:start:{$lockedExecution->id}:{$attempt->id}", actorId: 'development-orchestrator',
                    correlationId: $lockedExecution->correlation_id, execution: $lockedExecution,
                );
            }
            $startedEventId = $this->events->record(AuditEventType::ImplementationStarted, $lockedExecution, $attempt, $ticket, $lease);

            return [$lockedExecution->fresh(), $attempt, $ticket, $lease, $startedEventId];
        });

        /** @var Execution $lockedExecution */
        /** @var ExecutionAttempt $attempt */
        /** @var RoadmapTask $ticket */
        /** @var TicketExecutionLease $lease */
        [$lockedExecution, $attempt, $ticket, $lease, $startedEventId] = $started;

        try {
            $request = $this->request($lockedExecution, $attempt, $ticket, $lease, $scenario, $seed);
            $this->validator->validateRequest($request);
            $policy = $lockedExecution->projectContextSnapshot->configurationVersion->snapshot;
            $fallback = Arr::get($policy, 'policy.provider.fallback_order', []);
            $provider = $this->providers->resolve(is_array($fallback) ? array_values(array_filter($fallback, is_string(...))) : [], $lockedExecution->capability);
            $result = $provider->execute($request);
            $this->validator->validateResult($result);
        } catch (DevelopmentProviderTimeout $exception) {
            $this->failAndReleaseIfTerminal($lockedExecution, $attempt, $lease, 'development.provider_timeout', $exception->getMessage(), true, $startedEventId);

            return;
        } catch (\InvalidArgumentException $exception) {
            $this->failAndReleaseIfTerminal($lockedExecution, $attempt, $lease, 'development.invalid_result', $exception->getMessage(), false, $startedEventId);

            return;
        } catch (\Throwable $exception) {
            $this->failAndReleaseIfTerminal($lockedExecution, $attempt, $lease, 'development.provider_failure', $exception->getMessage(), true, $startedEventId);

            return;
        }

        if ($result->outcome === DevelopmentExecutionOutcome::ValidationFailed) {
            $this->recordValidationFailure($lockedExecution, $attempt, $ticket, $lease, $result, $startedEventId);

            return;
        }

        try {
            DB::transaction(function () use ($lockedExecution, $attempt, $ticket, $lease, $result, $startedEventId): void {
                $project = Project::query()->forOrganization($lockedExecution->project->organization_id)->whereKey($lockedExecution->project_id)->lock('for share')->firstOrFail();
                $execution = Execution::query()->forProject($project->id)->whereKey($lockedExecution->id)->lockForUpdate()->firstOrFail();
                $roadmap = Roadmap::query()->whereKey($ticket->roadmap_id)->where('project_id', $project->id)->lock('for share')->firstOrFail();
                $lockedTicket = RoadmapTask::query()->whereKey($ticket->id)->where('roadmap_id', $roadmap->id)->lockForUpdate()->firstOrFail();
                $lockedLease = TicketExecutionLease::query()->whereKey($lease->id)->where('project_id', $project->id)->where('execution_id', $execution->id)->where('roadmap_task_id', $lockedTicket->id)->active()->lockForUpdate()->firstOrFail();
                $lockedAttempt = ExecutionAttempt::query()->where('execution_id', $execution->id)->whereKey($attempt->id)->lockForUpdate()->firstOrFail();
                $execution->setRelation('project', $project);

                if ($execution->cancel_requested_at !== null) {
                    $this->attempts->completeAttempt($lockedAttempt, causationId: $startedEventId);
                    $execution->refresh();
                    $execution->setRelation('project', $project);
                    $this->leases->releaseLocked($project, $execution, $lockedLease, $lockedLease->owner, TicketLeaseReleaseReason::Cancellation);

                    return;
                }

                $this->recordArtifacts($execution, $lockedAttempt, $result);
                $causationId = $this->events->record(AuditEventType::ValidationStarted, $execution, $lockedAttempt, $lockedTicket, $lockedLease, $startedEventId);
                $causationId = $this->events->record(AuditEventType::ValidationCompleted, $execution, $lockedAttempt, $lockedTicket, $lockedLease, $causationId);
                $causationId = $this->events->record(AuditEventType::SyntheticCommitCreated, $execution, $lockedAttempt, $lockedTicket, $lockedLease, $causationId);
                $causationId = $this->events->record(AuditEventType::SyntheticPushRecorded, $execution, $lockedAttempt, $lockedTicket, $lockedLease, $causationId);
                $causationId = $this->events->record(AuditEventType::PullRequestCreated, $execution, $lockedAttempt, $lockedTicket, $lockedLease, $causationId);
                $this->attempts->completeAttempt($lockedAttempt, causationId: $causationId);
                $execution->refresh();
                $execution->setRelation('project', $project);
                $this->tickets->handleLocked(
                    project: $project, roadmap: $roadmap, ticket: $lockedTicket, target: TicketStatus::ForQa,
                    idempotencyKey: "development:complete:{$execution->id}:{$lockedAttempt->id}", actorId: 'development-orchestrator',
                    correlationId: $execution->correlation_id, execution: $execution,
                );
                $this->events->record(AuditEventType::ImplementationCompleted, $execution, $lockedAttempt, $lockedTicket->refresh(), $lockedLease, $causationId);
                $this->leases->releaseLocked($project, $execution, $lockedLease, $lockedLease->owner, TicketLeaseReleaseReason::Completion);
            });
        } catch (\Throwable $exception) {
            $this->failAndReleaseIfTerminal($lockedExecution, $attempt, $lease, 'development.terminal_persistence_failure', $exception->getMessage(), true, $startedEventId);
        }
    }

    private function request(Execution $execution, ExecutionAttempt $attempt, RoadmapTask $ticket, TicketExecutionLease $lease, string $scenario, int $seed): DevelopmentExecutionRequest
    {
        $execution->loadMissing('project', 'projectContextSnapshot.configurationVersion');
        $ticket->loadMissing('dependencies.dependsOn');
        $snapshot = $execution->projectContextSnapshot;
        $configuration = $snapshot->configurationVersion->snapshot;
        $scope = $ticket->scope;
        $validationCommands = Arr::get($configuration, 'policy.validation.commands', ['php artisan test']);

        return new DevelopmentExecutionRequest(
            organizationId: $execution->project->organization_id, projectId: $execution->project_id,
            roadmapId: $ticket->roadmap_id, ticketId: $ticket->stable_id, executionId: $execution->id,
            attemptId: $attempt->id, attemptNumber: $attempt->attempt_number, leaseId: $lease->id,
            contextSnapshotId: $snapshot->id, contextFingerprint: $snapshot->approved_document_set_fingerprint,
            ticketObjective: $ticket->objective, includedScope: $this->strings($scope['included']),
            excludedScope: $this->strings($scope['excluded']), acceptanceCriteria: $this->strings($ticket->acceptance_criteria),
            dependencyReferences: array_values($ticket->dependencies
                ->map(static fn ($dependency): string => $dependency->dependsOn->stable_id)
                ->sort()
                ->values()
                ->all()),
            evidenceRequirements: $this->strings($ticket->evidence_requirements),
            risk: $ticket->risk, complexity: $ticket->estimated_complexity,
            repositoryProviderMetadata: ['provider' => 'simulation', 'ticket_type' => $ticket->ticket_type],
            repositoryBaseReference: "simulation://projects/{$execution->project_id}/base/develop", integrationTarget: 'develop',
            validationCommands: $this->strings($validationCommands), requestedReasoning: $execution->requested_reasoning_level->value,
            effectiveReasoning: $attempt->effective_reasoning_level->value, reasoningResolutionSource: $attempt->reasoning_resolution_source,
            providerPolicy: (array) Arr::get($configuration, 'policy.provider', []), budgetPolicy: (array) Arr::get($configuration, 'policy.budget', []),
            retryPolicy: ['retry_limit' => $execution->retry_limit], simulationScenario: $scenario, deterministicSeed: $seed,
        );
    }

    private function recordArtifacts(Execution $execution, ExecutionAttempt $attempt, DevelopmentExecutionResult $result): void
    {
        $root = "simulation://projects/{$execution->project_id}/executions/{$execution->id}";
        $this->artifacts->record($execution, $attempt, 'implementation_plan', 'Simulated implementation plan', "{$root}/artifacts/plan", ['plan' => $result->implementationPlan], ['Synthetic implementation plan generated.']);
        $this->artifacts->record($execution, $attempt, 'changed_file_manifest', 'Simulated changed-file manifest', "{$root}/artifacts/changed-files", ['files' => array_map(static fn ($file): array => $file->toArray(), $result->changedFiles)], ['Synthetic changed-file manifest generated.']);
        $this->artifacts->record($execution, $attempt, 'validation_result', 'Simulated validation result', "{$root}/artifacts/validation", ['validations' => array_map(static fn ($validation): array => $validation->toArray(), $result->validationResults)], ['Synthetic validation passed.']);
        foreach ([$result->syntheticBranchResult, $result->syntheticCommitResult, $result->syntheticPushResult, $result->syntheticPullRequestResult] as $artifact) {
            if ($artifact !== null) {
                $this->artifacts->record($execution, $attempt, 'synthetic_'.$artifact->kind, $artifact->identifier, $artifact->reference, $artifact->toArray(), ["Synthetic {$artifact->kind} generated."]);
            }
        }
    }

    private function recordValidationFailure(
        Execution $execution,
        ExecutionAttempt $attempt,
        RoadmapTask $ticket,
        TicketExecutionLease $lease,
        DevelopmentExecutionResult $result,
        string $startedEventId,
    ): void {
        DB::transaction(function () use ($execution, $attempt, $ticket, $lease, $result, $startedEventId): void {
            $lockedExecution = Execution::query()->forProject($execution->project_id)->whereKey($execution->id)->lockForUpdate()->firstOrFail();
            $lockedTicket = RoadmapTask::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $lockedLease = TicketExecutionLease::query()->whereKey($lease->id)->active()->lockForUpdate()->firstOrFail();
            $lockedAttempt = ExecutionAttempt::query()->where('execution_id', $execution->id)->whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            $reference = "simulation://projects/{$execution->project_id}/executions/{$execution->id}/attempts/{$attempt->id}/validation-failure";
            $this->artifacts->record(
                $lockedExecution, $lockedAttempt, 'validation_failure', 'Simulated validation failure', $reference,
                ['validations' => array_map(static fn ($validation): array => $validation->toArray(), $result->validationResults)],
                ['Simulated validation failed; real evidence remains required.'],
            );
            $causationId = $this->events->record(AuditEventType::ValidationStarted, $lockedExecution, $lockedAttempt, $lockedTicket, $lockedLease, $startedEventId);
            $causationId = $this->events->record(AuditEventType::ValidationFailed, $lockedExecution, $lockedAttempt, $lockedTicket, $lockedLease, $causationId);
            $this->failAndReleaseIfTerminal($lockedExecution, $lockedAttempt, $lockedLease, 'development.validation_failed', 'Simulated development validation failed.', true, $causationId);
        });
    }

    private function failAndReleaseIfTerminal(
        Execution $execution,
        ExecutionAttempt $attempt,
        TicketExecutionLease $lease,
        string $errorCode,
        string $message,
        bool $retryable,
        string $causationId,
    ): void {
        $decision = $this->attempts->failAttempt(
            $attempt, $errorCode, $this->redactor->message($message), $retryable,
            causationId: $causationId,
        );

        if ($decision->executionStatus === ExecutionStatus::Failed) {
            $execution->refresh();
            $this->leases->releaseForExecution(
                $execution->project->organization_id, $execution->project_id, $lease->id,
                $execution->id, $lease->owner, TicketLeaseReleaseReason::TerminalFailure,
            );
        }
    }

    /** @return list<string> */
    private function strings(mixed $value): array
    {
        return is_array($value) && array_is_list($value) ? array_values(array_filter($value, is_string(...))) : [];
    }
}
