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
use App\Domain\Executions\ExecutionStatus;
use App\Domain\Tickets\TicketLeaseReleaseReason;
use App\Domain\Tickets\TicketStatus;
use App\Models\Execution;
use App\Models\ExecutionAttempt;
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
            $lockedExecution = Execution::query()->forProject($execution->project_id)->whereKey($execution->id)->lockForUpdate()->firstOrFail();

            if ($lockedExecution->capability !== 'development.simulation' || $lockedExecution->status !== ExecutionStatus::Queued || $lockedExecution->cancel_requested_at !== null) {
                throw new \LogicException('Development execution cannot start.');
            }

            $leaseIdentity = TicketExecutionLease::query()->where('execution_id', $lockedExecution->id)->active()->firstOrFail();
            $ticket = RoadmapTask::query()->whereKey($leaseIdentity->roadmap_task_id)->lockForUpdate()->firstOrFail();
            $lease = TicketExecutionLease::query()->whereKey($leaseIdentity->id)->active()->lockForUpdate()->firstOrFail();
            $roadmap = Roadmap::query()->whereKey($ticket->roadmap_id)->where('project_id', $lockedExecution->project_id)->where('project_context_snapshot_id', $lockedExecution->project_context_snapshot_id)->firstOrFail();

            if ($lease->project_id !== $lockedExecution->project_id || $lease->roadmap_task_id !== $ticket->id || ! in_array($ticket->status, [TicketStatus::Ready, TicketStatus::ChangesRequested], true)) {
                throw new \LogicException('Development execution lineage is invalid.');
            }

            $attempt = $this->attempts->startAttempt($lockedExecution, new ExecutionAttemptContext(
                executionProvider: 'simulation', modelIdentifier: null,
                requestedReasoningLevel: $lockedExecution->requested_reasoning_level,
                effectiveReasoningLevel: $lockedExecution->requested_reasoning_level,
                reasoningResolutionSource: 'immutable_configuration_snapshot', simulationMode: 'simulated', simulationSeed: (string) $seed,
            ));
            $ticket = $this->tickets->handle(
                organizationId: $lockedExecution->project->organization_id, projectId: $lockedExecution->project_id,
                roadmapId: $roadmap->id, ticketId: $ticket->id, target: TicketStatus::InProgress,
                idempotencyKey: "development:start:{$lockedExecution->id}:{$attempt->id}", actorId: 'development-orchestrator',
                correlationId: $lockedExecution->correlation_id, executionId: $lockedExecution->id,
            );
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
        } catch (\InvalidArgumentException $exception) {
            $this->attempts->failAttempt($attempt, 'development.invalid_result', $this->redactor->message($exception->getMessage()), false, causationId: $startedEventId);

            return;
        } catch (\Throwable $exception) {
            $this->attempts->failAttempt($attempt, 'development.provider_failure', $this->redactor->message($exception->getMessage()), true, causationId: $startedEventId);

            return;
        }

        try {
            DB::transaction(function () use ($lockedExecution, $attempt, $ticket, $lease, $result, $startedEventId): void {
                $execution = Execution::query()->forProject($lockedExecution->project_id)->whereKey($lockedExecution->id)->lockForUpdate()->firstOrFail();
                $lockedTicket = RoadmapTask::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
                $lockedLease = TicketExecutionLease::query()->whereKey($lease->id)->where('execution_id', $execution->id)->active()->lockForUpdate()->firstOrFail();
                $lockedAttempt = ExecutionAttempt::query()->where('execution_id', $execution->id)->whereKey($attempt->id)->lockForUpdate()->firstOrFail();

                if ($execution->cancel_requested_at !== null) {
                    $this->attempts->completeAttempt($lockedAttempt, causationId: $startedEventId);
                    $execution->refresh();
                    $this->leases->releaseForExecution($execution->project->organization_id, $execution->project_id, $lockedLease->id, $execution->id, $lockedLease->owner, TicketLeaseReleaseReason::Cancellation);

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
                $this->tickets->handle(
                    organizationId: $execution->project->organization_id, projectId: $execution->project_id,
                    roadmapId: $lockedTicket->roadmap_id, ticketId: $lockedTicket->id, target: TicketStatus::ForQa,
                    idempotencyKey: "development:complete:{$execution->id}:{$lockedAttempt->id}", actorId: 'development-orchestrator',
                    correlationId: $execution->correlation_id, executionId: $execution->id,
                );
                $this->events->record(AuditEventType::ImplementationCompleted, $execution, $lockedAttempt, $lockedTicket->refresh(), $lockedLease, $causationId);
                $this->leases->releaseForExecution($execution->project->organization_id, $execution->project_id, $lockedLease->id, $execution->id, $lockedLease->owner, TicketLeaseReleaseReason::Completion);
            });
        } catch (\Throwable $exception) {
            $this->attempts->failAttempt(
                $attempt,
                'development.terminal_persistence_failure',
                $this->redactor->message($exception->getMessage()),
                true,
                causationId: $startedEventId,
            );
        }
    }

    private function request(Execution $execution, ExecutionAttempt $attempt, RoadmapTask $ticket, TicketExecutionLease $lease, string $scenario, int $seed): DevelopmentExecutionRequest
    {
        $execution->loadMissing('project', 'projectContextSnapshot.configurationVersion');
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
            dependencyReferences: [], evidenceRequirements: $this->strings($ticket->evidence_requirements),
            risk: $ticket->risk, complexity: $ticket->estimated_complexity,
            repositoryProviderMetadata: ['provider' => 'simulation'],
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

    /** @return list<string> */
    private function strings(mixed $value): array
    {
        return is_array($value) && array_is_list($value) ? array_values(array_filter($value, is_string(...))) : [];
    }
}
