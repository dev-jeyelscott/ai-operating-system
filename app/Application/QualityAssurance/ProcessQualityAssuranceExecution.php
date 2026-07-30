<?php

declare(strict_types=1);

namespace App\Application\QualityAssurance;

use App\Application\Executions\Data\ExecutionAttemptContext;
use App\Application\Executions\ExecutionResilienceManager;
use App\Application\QualityAssurance\Data\QaAssessmentResult;
use App\Application\QualityAssurance\Data\QualityAssuranceExecutionRequest;
use App\Application\Security\RedactSensitiveData;
use App\Domain\Audit\AuditEventType;
use App\Domain\Evidence\EvidenceClassification;
use App\Domain\Executions\ExecutionStatus;
use App\Models\Artifact;
use App\Models\Evidence;
use App\Models\Execution;
use App\Models\ExecutionAttempt;
use App\Models\Project;
use App\Models\QaAssessment;
use App\Models\RoadmapTask;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Throwable;

/**
 * Orchestrates one independent, deterministic Layer 3 simulation execution.
 */
final readonly class ProcessQualityAssuranceExecution
{
    public function __construct(
        private QualityAssuranceProviderRegistry $providers,
        private QaAssessmentValidator $validator,
        private BuildForQaTicketEligibilityContext $contexts,
        private ForQaTicketEligibilityEvaluator $eligibility,
        private Layer3RoleIndependencePolicy $independence,
        private ExecutionResilienceManager $attempts,
        private RecordQualityAssuranceLifecycleEvent $events,
        private RedactSensitiveData $redactor,
    ) {}

    /**
     * Execute one queued QA assessment.
     */
    public function handle(QaAssessment $assessment): void
    {
        $started = DB::transaction(
            function () use ($assessment): ?array {
                $lockedAssessment = QaAssessment::query()
                    ->whereKey($assessment->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $project = Project::query()
                    ->whereKey($lockedAssessment->project_id)
                    ->lock('for share')
                    ->firstOrFail();

                $reviewExecution = Execution::query()
                    ->forProject($project->id)
                    ->whereKey(
                        $lockedAssessment->review_execution_id,
                    )
                    ->where(
                        'capability',
                        'quality_assurance.simulation',
                    )
                    ->lockForUpdate()
                    ->firstOrFail();

                /*
                 * Makes queue replay safe after successful completion or a
                 * terminal failure.
                 */
                if (
                    $reviewExecution->status
                        !== ExecutionStatus::Queued
                    || $reviewExecution->cancel_requested_at !== null
                ) {
                    return null;
                }

                $implementationExecution = Execution::query()
                    ->forProject($project->id)
                    ->whereKey(
                        $lockedAssessment
                            ->implementation_execution_id,
                    )
                    ->whereIn('capability', [
                        'development',
                        'development.simulation',
                    ])
                    ->lock('for share')
                    ->firstOrFail();

                $implementationAttempt =
                    ExecutionAttempt::query()
                        ->where(
                            'execution_id',
                            $implementationExecution->id,
                        )
                        ->whereKey(
                            $lockedAssessment
                                ->implementation_attempt_id,
                        )
                        ->lock('for share')
                        ->firstOrFail();

                $ticket = RoadmapTask::query()
                    ->whereKey(
                        $lockedAssessment->roadmap_task_id,
                    )
                    ->whereHas(
                        'roadmap',
                        static fn ($query) => $query->where(
                            'project_id',
                            $project->id,
                        ),
                    )
                    ->lockForUpdate()
                    ->firstOrFail();

                $ticket->load('roadmap');

                $eligibility = $this->eligibility->evaluate(
                    $this->contexts->build(
                        ticket: $ticket,
                        implementationExecution: $implementationExecution,
                        implementationAttempt: $implementationAttempt,
                    ),
                );

                if (! $eligibility->isEligible()) {
                    throw new LogicException(sprintf(
                        'QA eligibility changed before execution: %s',
                        implode(
                            ', ',
                            $eligibility->reasonValues(),
                        ),
                    ));
                }

                $independence = $this->independence->evaluate(
                    implementationExecution: $implementationExecution,
                    reviewExecution: $reviewExecution,
                );

                if (! $independence->allowed) {
                    throw new LogicException(sprintf(
                        'Layer 3 independence policy failed: %s',
                        $independence->reason?->value ?? 'unknown',
                    ));
                }

                $reviewAttempt = $this->attempts->startAttempt(
                    execution: $reviewExecution,
                    context: new ExecutionAttemptContext(
                        executionProvider: 'simulation',
                        modelIdentifier: null,
                        requestedReasoningLevel: $reviewExecution
                            ->requested_reasoning_level,
                        effectiveReasoningLevel: $reviewExecution
                            ->requested_reasoning_level,
                        reasoningResolutionSource: 'layer_3_final_qa_policy',
                        reasoningEscalationReason: 'final_qa_and_merge_advisory',
                        simulationMode: 'simulated',
                        simulationSeed: (string)
                            $lockedAssessment->simulation_seed,
                    ),
                );

                $lockedAssessment->markRunning(
                    $reviewAttempt->id,
                );

                $startedEventId = $this->events->record(
                    eventType: AuditEventType::QaStarted,
                    assessment: $lockedAssessment,
                    reviewExecution: $reviewExecution,
                    reviewAttempt: $reviewAttempt,
                    implementationExecution: $implementationExecution,
                    ticket: $ticket,
                );

                return [
                    $lockedAssessment->fresh(),
                    $reviewExecution->fresh(),
                    $reviewAttempt,
                    $implementationExecution,
                    $implementationAttempt,
                    $ticket,
                    $startedEventId,
                ];
            },
            attempts: 3,
        );

        if ($started === null) {
            return;
        }

        /** @var QaAssessment $runningAssessment */
        /** @var Execution $reviewExecution */
        /** @var ExecutionAttempt $reviewAttempt */
        /** @var Execution $implementationExecution */
        /** @var ExecutionAttempt $implementationAttempt */
        /** @var RoadmapTask $ticket */
        [
            $runningAssessment,
            $reviewExecution,
            $reviewAttempt,
            $implementationExecution,
            $implementationAttempt,
            $ticket,
            $startedEventId,
        ] = $started;

        try {
            /*
             * Provider execution deliberately occurs outside every database
             * transaction so no project, ticket, or execution lock is held
             * while external or simulated work runs.
             */
            $request = $this->buildRequest(
                assessment: $runningAssessment,
                reviewExecution: $reviewExecution,
                reviewAttempt: $reviewAttempt,
                implementationExecution: $implementationExecution,
                implementationAttempt: $implementationAttempt,
                ticket: $ticket,
            );

            $configuration = $reviewExecution
                ->projectContextSnapshot
                ->configurationVersion
                ->snapshot;

            $fallbackOrder = Arr::get(
                $configuration,
                'policy.provider.fallback_order',
                [],
            );

            $provider = $this->providers->resolve(
                fallbackOrder: is_array($fallbackOrder)
                        ? array_values(array_filter(
                            $fallbackOrder,
                            is_string(...),
                        ))
                        : [],
                capability: $reviewExecution->capability,
            );

            $result = $provider->execute($request);

            $this->validator->validateAssessment($result);
            $this->assertKnownEvidenceReferences(
                result: $result,
                request: $request,
            );
        } catch (InvalidArgumentException|LogicException $exception) {
            $this->failAttempt(
                assessment: $runningAssessment,
                attempt: $reviewAttempt,
                errorCode: 'quality_assurance.invalid_result',
                message: $exception->getMessage(),
                retryable: false,
                causationId: $startedEventId,
            );

            return;
        } catch (Throwable $exception) {
            $this->failAttempt(
                assessment: $runningAssessment,
                attempt: $reviewAttempt,
                errorCode: 'quality_assurance.provider_failure',
                message: $exception->getMessage(),
                retryable: true,
                causationId: $startedEventId,
            );

            return;
        }

        try {
            DB::transaction(
                function () use (
                    $runningAssessment,
                    $reviewExecution,
                    $reviewAttempt,
                    $implementationExecution,
                    $ticket,
                    $result,
                    $startedEventId,
                ): void {
                    $assessment = QaAssessment::query()
                        ->whereKey($runningAssessment->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $lockedReviewExecution = Execution::query()
                        ->forProject($assessment->project_id)
                        ->whereKey($reviewExecution->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $lockedReviewAttempt =
                        ExecutionAttempt::query()
                            ->where(
                                'execution_id',
                                $lockedReviewExecution->id,
                            )
                            ->whereKey($reviewAttempt->id)
                            ->lockForUpdate()
                            ->firstOrFail();

                    $lockedImplementationExecution =
                        Execution::query()
                            ->forProject($assessment->project_id)
                            ->whereKey(
                                $implementationExecution->id,
                            )
                            ->lock('for share')
                            ->firstOrFail();

                    $lockedTicket = RoadmapTask::query()
                        ->whereKey($ticket->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $lockedReviewExecution->setRelation(
                        'project',
                        Project::query()->findOrFail(
                            $assessment->project_id,
                        ),
                    );

                    if (
                        $lockedReviewExecution
                            ->cancel_requested_at !== null
                    ) {
                        $this->attempts->completeAttempt(
                            attempt: $lockedReviewAttempt,
                            causationId: $startedEventId,
                        );

                        $assessment->markCancelled();

                        return;
                    }

                    $this->recordResult(
                        assessment: $assessment,
                        reviewExecution: $lockedReviewExecution,
                        reviewAttempt: $lockedReviewAttempt,
                        result: $result,
                    );

                    $completedEventId = $this->events->record(
                        eventType: AuditEventType::MergeAssessmentCompleted,
                        assessment: $assessment->fresh(),
                        reviewExecution: $lockedReviewExecution,
                        reviewAttempt: $lockedReviewAttempt,
                        implementationExecution: $lockedImplementationExecution,
                        ticket: $lockedTicket,
                        causationId: $startedEventId,
                        additionalPayload: [
                            'decision' => $result->decision->value,
                            'confidence' => $result->confidence,
                            'target_branch' => $result->targetBranch,
                            'assessment_fingerprint' => $result
                                ->canonicalAssessmentFingerprint,
                        ],
                    );

                    $this->attempts->completeAttempt(
                        attempt: $lockedReviewAttempt,
                        causationId: $completedEventId,
                    );
                },
                attempts: 3,
            );
        } catch (Throwable $exception) {
            $this->failAttempt(
                assessment: $runningAssessment,
                attempt: $reviewAttempt,
                errorCode: 'quality_assurance.terminal_persistence_failure',
                message: $exception->getMessage(),
                retryable: true,
                causationId: $startedEventId,
            );
        }
    }

    /**
     * Build the provider request from authoritative ticket and Layer 2 records.
     */
    private function buildRequest(
        QaAssessment $assessment,
        Execution $reviewExecution,
        ExecutionAttempt $reviewAttempt,
        Execution $implementationExecution,
        ExecutionAttempt $implementationAttempt,
        RoadmapTask $ticket,
    ): QualityAssuranceExecutionRequest {
        $reviewExecution->loadMissing(
            'project',
            'projectContextSnapshot.configurationVersion',
        );

        $ticket->loadMissing('roadmap');

        $artifacts = Artifact::query()
            ->forProject($reviewExecution->project_id)
            ->forExecution($implementationExecution->id)
            ->where(
                'execution_attempt_id',
                $implementationAttempt->id,
            )
            ->with('evidence')
            ->orderBy('artifact_type')
            ->orderBy('id')
            ->get();

        $evidenceIds = $artifacts
            ->flatMap(
                static fn (Artifact $artifact): array => $artifact->evidence
                    ->pluck('id')
                    ->all(),
            )
            ->filter(is_string(...))
            ->unique()
            ->sort()
            ->values()
            ->all();

        $implementationArtifacts = $artifacts
            ->map(static function (Artifact $artifact): array {
                return [
                    'artifact_id' => $artifact->id,
                    'artifact_type' => $artifact->artifact_type,
                    'name' => $artifact->name,
                    'external_reference' => $artifact->external_reference,
                    'actual_state' => $artifact->actual_state,
                    'evidence_still_required' => $artifact->evidence_still_required,
                    'metadata' => $artifact->metadata,
                    'evidence_ids' => $artifact->evidence
                        ->pluck('id')
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();

        $pullRequest = $artifacts->firstWhere(
            'artifact_type',
            'synthetic_pull_request',
        );

        $targetBranch = is_array($pullRequest?->metadata)
            ? ($pullRequest->metadata['target_branch'] ?? null)
            : null;

        if ($targetBranch !== 'develop') {
            throw new InvalidArgumentException(
                'Layer 3 input pull request must target develop.',
            );
        }

        $scope = is_array($ticket->scope)
            ? $ticket->scope
            : [];

        $configuration = $reviewExecution
            ->projectContextSnapshot
            ->configurationVersion
            ->snapshot;

        return new QualityAssuranceExecutionRequest(
            organizationId: $reviewExecution->project->organization_id,
            projectId: $reviewExecution->project_id,
            roadmapId: $ticket->roadmap_id,
            roadmapTaskId: $ticket->id,
            ticketId: $ticket->stable_id,
            reviewExecutionId: $reviewExecution->id,
            reviewAttemptId: $reviewAttempt->id,
            implementationExecutionId: $implementationExecution->id,
            implementationAttemptId: $implementationAttempt->id,
            contextSnapshotId: $reviewExecution->project_context_snapshot_id,
            contextFingerprint: $reviewExecution
                ->projectContextSnapshot
                ->approved_document_set_fingerprint,
            ticketObjective: $ticket->objective,
            includedScope: $this->stringList(
                $scope['included'] ?? [],
            ),
            excludedScope: $this->stringList(
                $scope['excluded'] ?? [],
            ),
            acceptanceCriteria: is_array($ticket->acceptance_criteria)
                    ? array_values(
                        $ticket->acceptance_criteria,
                    )
                    : [],
            requiredEvidence: $this->stringList(
                $ticket->evidence_requirements,
            ),
            ticketRisk: $ticket->risk,
            targetBranch: $targetBranch,
            implementationLogicalRole: $implementationExecution->logical_role
                    ?? 'unknown',
            reviewLogicalRole: $reviewExecution->logical_role
                    ?? 'unknown',
            implementationArtifacts: $implementationArtifacts,
            evidenceIds: $evidenceIds,
            requestedReasoning: $reviewExecution
                ->requested_reasoning_level
                ->value,
            effectiveReasoning: $reviewAttempt
                ->effective_reasoning_level
                ->value,
            reasoningResolutionSource: $reviewAttempt
                ->reasoning_resolution_source,
            providerPolicy: (array) Arr::get(
                $configuration,
                'policy.provider',
                [],
            ),
            simulationScenario: $assessment->simulation_scenario,
            deterministicSeed: $assessment->simulation_seed,
        );
    }

    /**
     * Ensure the provider cannot reference evidence outside the request lineage.
     */
    private function assertKnownEvidenceReferences(
        QaAssessmentResult $result,
        QualityAssuranceExecutionRequest $request,
    ): void {
        $unknownEvidenceIds = array_values(array_diff(
            $result->evidenceIds,
            $request->evidenceIds,
        ));

        if ($unknownEvidenceIds !== []) {
            throw new InvalidArgumentException(
                'QA result references evidence outside the Layer 2 execution lineage.',
            );
        }
    }

    /**
     * Persist the report as both a queryable assessment and simulated evidence.
     */
    private function recordResult(
        QaAssessment $assessment,
        Execution $reviewExecution,
        ExecutionAttempt $reviewAttempt,
        QaAssessmentResult $result,
    ): void {
        $reference = sprintf(
            'simulation://projects/%d/executions/%s/qa-assessment',
            $reviewExecution->project_id,
            $reviewExecution->id,
        );

        $metadata = [
            'synthetic' => true,
            'actual_state' => 'unverified',
            'qa_assessment_id' => $assessment->id,
            'implementation_execution_id' => $assessment->implementation_execution_id,
            'result' => $result->toArray(),
        ];

        $artifactFingerprint = hash(
            'sha256',
            $this->validator->canonicalJson([
                'artifact_type' => 'qa_assessment',
                'reference' => $reference,
                'metadata' => $metadata,
            ]),
        );

        $claims = [
            sprintf(
                'Synthetic Layer 3 decision: %s.',
                $result->decision->value,
            ),
            'The assessment remains unverified and cannot authorize a real merge.',
        ];

        $evidenceMetadata = [
            'synthetic' => true,
            'actual_state' => 'unverified',
            'qa_assessment_id' => $assessment->id,
        ];

        $evidenceFingerprint = hash(
            'sha256',
            $this->validator->canonicalJson([
                'classification' => EvidenceClassification::SimulatedOutput
                    ->value,
                'reference' => $reference,
                'claims' => $claims,
                'metadata' => $evidenceMetadata,
            ]),
        );

        $assessment->markCompleted($result);

        $artifact = Artifact::query()->create([
            'project_id' => $reviewExecution->project_id,
            'execution_id' => $reviewExecution->id,
            'execution_attempt_id' => $reviewAttempt->id,
            'artifact_type' => 'qa_assessment',
            'name' => 'Simulated Layer 3 QA assessment',
            'execution_provider' => 'simulation',
            'external_reference' => $reference,
            'media_type' => 'application/json',
            'simulation_mode' => 'simulated',
            'simulation_seed' => $reviewAttempt->simulation_seed,
            'assumptions' => [
                'Synthetic Layer 3 assessment only.',
                'Verified implementation, CI, and review evidence remains required.',
            ],
            'confidence' => number_format(
                $result->confidence,
                4,
                '.',
                '',
            ),
            'evidence_still_required' => true,
            'actual_state' => 'unverified',
            'metadata' => $metadata,
            'idempotency_key' => sprintf(
                'quality-assurance:%s:qa-assessment',
                $reviewExecution->id,
            ),
            'content_fingerprint_sha256' => $artifactFingerprint,
        ]);

        Evidence::query()->create([
            'artifact_id' => $artifact->id,
            'classification' => EvidenceClassification::SimulatedOutput,
            'evidence_type' => 'qa_assessment',
            'provider' => 'simulation',
            'source_reference' => $reference,
            'claims' => $claims,
            'confidence' => number_format(
                $result->confidence,
                4,
                '.',
                '',
            ),
            'metadata' => $evidenceMetadata,
            'provenance_key' => 'quality-assurance:qa-assessment:simulated-output',
            'content_fingerprint_sha256' => $evidenceFingerprint,
        ]);
    }

    /**
     * Fail the provider attempt through the shared resilience manager.
     */
    private function failAttempt(
        QaAssessment $assessment,
        ExecutionAttempt $attempt,
        string $errorCode,
        string $message,
        bool $retryable,
        string $causationId,
    ): void {
        $decision = $this->attempts->failAttempt(
            attempt: $attempt,
            errorCode: $errorCode,
            errorMessage: $this->redactor->message($message),
            retryable: $retryable,
            causationId: $causationId,
        );

        DB::transaction(function () use (
            $assessment,
            $decision,
        ): void {
            $lockedAssessment = QaAssessment::query()
                ->whereKey($assessment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $decision->executionStatus
                    === ExecutionStatus::RetryScheduled
            ) {
                $lockedAssessment->markRetryScheduled();

                return;
            }

            if (
                $decision->executionStatus
                    === ExecutionStatus::Failed
            ) {
                $lockedAssessment->markFailed();
            }
        });
    }

    /**
     * Normalize an untrusted mixed value into a list of strings.
     *
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return [];
        }

        return array_values(array_filter(
            $value,
            is_string(...),
        ));
    }
}
