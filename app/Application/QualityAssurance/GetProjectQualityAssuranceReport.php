<?php

declare(strict_types=1);

namespace App\Application\QualityAssurance;

use App\Domain\QualityAssurance\MergeDecisionAction;
use App\Models\Evidence;
use App\Models\ExecutionAttempt;
use App\Models\MergeDecision;
use App\Models\Project;
use App\Models\QaAssessment;
use App\Models\RoadmapTask;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Builds the latest project-scoped Layer 3 QA report read model.
 */
final readonly class GetProjectQualityAssuranceReport
{
    public function __construct(
        private SimulatedMergeDecisionPolicy $decisionPolicy,
    ) {}

    /**
     * Return the latest QA assessment without exposing raw provider metadata.
     *
     * @return array<string, mixed>
     */
    public function handle(
        int $organizationId,
        int $projectId,
        ?int $actorUserId = null,
    ): array {
        $asOf = CarbonImmutable::now();

        $project = Project::query()
            ->where('organization_id', $organizationId)
            ->whereKey($projectId)
            ->with('organization')
            ->firstOrFail();

        $assessment = QaAssessment::query()
            ->forProject($projectId)
            ->with([
                'ticket:id,stable_id,title,objective,status,actual_state',
                'reviewAttempt:id,execution_id,status,execution_provider,simulation_mode,simulation_seed,actual_state',
            ])
            ->latest('created_at')
            ->first();

        if (! $assessment instanceof QaAssessment) {
            return [
                'asOf' => $asOf->toIso8601String(),
                'assessment' => null,
            ];
        }

        $evidenceIds = $this->referencedEvidenceIds($assessment);
        $evidenceById = $this->evidenceRecords(
            projectId: $projectId,
            evidenceIds: $evidenceIds,
        );

        return [
            'asOf' => $asOf->toIso8601String(),
            'assessment' => $this->serializeAssessment(
                assessment: $assessment,
                evidenceIds: $evidenceIds,
                evidenceById: $evidenceById,
                asOf: $asOf,
                project: $project,
                actorUserId: $actorUserId,
            ),
        ];
    }

    /**
     * Serialize the canonical QA assessment into the public Inertia contract.
     *
     * @param  list<string>  $evidenceIds
     * @param  Collection<string, Evidence>  $evidenceById
     * @return array<string, mixed>
     */
    private function serializeAssessment(
        QaAssessment $assessment,
        array $evidenceIds,
        Collection $evidenceById,
        CarbonImmutable $asOf,
        Project $project,
        ?int $actorUserId,
    ): array {
        $ticket = $assessment->ticket;
        $reviewAttempt = $assessment->reviewAttempt;
        $storedActualState = $reviewAttempt instanceof ExecutionAttempt
            ? ($reviewAttempt->actual_state ?? 'unverified')
            : 'unverified';
        $isSimulated = $reviewAttempt instanceof ExecutionAttempt
            && $reviewAttempt->execution_provider === 'simulation';
        $evidenceReferences = array_map(
            fn (string $evidenceId): array => $this
                ->serializeEvidenceReference(
                    evidenceId: $evidenceId,
                    evidence: $evidenceById->get($evidenceId),
                    asOf: $asOf,
                ),
            $evidenceIds,
        );
        $evidenceSummary = $this->summarizeEvidence($evidenceReferences);
        $actualState = ! $isSimulated
            && $storedActualState === 'verified'
            && $evidenceSummary['allCurrentlyVerified']
                ? 'verified'
                : 'unverified';
        $decisionCenter = $this->decisionCenter(
            assessment: $assessment,
            project: $project,
            ticket: $ticket,
            actorUserId: $actorUserId,
        );

        return [
            'id' => $assessment->id,
            'status' => $assessment->status,
            'ticket' => $ticket instanceof RoadmapTask
                ? [
                    'id' => $ticket->stable_id,
                    'title' => $ticket->title,
                    'objective' => $ticket->objective,
                    'status' => $ticket->status->value,
                    'actualState' => $ticket->actual_state->value,
                ]
                : null,
            'decision' => $assessment->decision?->value,
            'confidence' => $assessment->confidence !== null
                ? (float) $assessment->confidence
                : null,
            'targetBranch' => $assessment->target_branch,
            'ticketScopeSatisfied' => $assessment->ticket_scope_satisfied,
            'acceptanceCriteriaVerified' => $assessment
                ->acceptance_criteria_verified,
            'reviewStatuses' => [
                ['label' => 'CI', 'status' => $assessment->ci_status?->value],
                ['label' => 'Tests', 'status' => $assessment->test_status?->value],
                [
                    'label' => 'Architecture',
                    'status' => $assessment->architecture_status?->value,
                ],
                [
                    'label' => 'Security',
                    'status' => $assessment->security_status?->value,
                ],
            ],
            'riskMatrix' => [
                [
                    'label' => 'Database impact',
                    'level' => $assessment->database_impact?->value,
                ],
                [
                    'label' => 'Performance impact',
                    'level' => $assessment->performance_impact?->value,
                ],
                [
                    'label' => 'Regression risk',
                    'level' => $assessment->regression_risk?->value,
                ],
                [
                    'label' => 'Rollback complexity',
                    'level' => $assessment->rollback_complexity?->value,
                ],
            ],
            'findings' => $this->normalizeFindings(
                $assessment->unresolved_findings,
            ),
            'mergeRisks' => $this->normalizeMergeRisks(
                $assessment->merge_risks,
            ),
            'recommendation' => $assessment->recommendation,
            'evidenceReferences' => $evidenceReferences,
            'evidenceSummary' => $evidenceSummary,
            'decisionCenter' => $decisionCenter,
            'provenance' => [
                'isSimulated' => $isSimulated,
                'provider' => $reviewAttempt instanceof ExecutionAttempt
                    ? $reviewAttempt->execution_provider
                    : null,
                'scenario' => $assessment->simulation_scenario,
                'seed' => $assessment->simulation_seed,
                'actualState' => $actualState,
                'evidenceStillRequired' => $actualState !== 'verified',
                'schemaVersion' => $assessment->result_schema_version,
                'fingerprint' => $assessment
                    ->canonical_assessment_fingerprint,
            ],
            'createdAt' => $assessment->created_at->toIso8601String(),
            'updatedAt' => $assessment->updated_at->toIso8601String(),
        ];
    }

    /**
     * Build the safe human decision contract from authoritative state.
     *
     * @return array<string, mixed>
     */
    private function decisionCenter(
        QaAssessment $assessment,
        Project $project,
        mixed $ticket,
        ?int $actorUserId,
    ): array {
        $latestDecision = MergeDecision::query()
            ->forProject($project->id)
            ->where('qa_assessment_id', $assessment->id)
            ->latest('decided_at')
            ->first();
        $terminal = $latestDecision?->terminal_marker === 'T';
        $actor = $actorUserId === null
            ? null
            : User::query()->find($actorUserId);
        $authorized = $actor instanceof User
            && Gate::forUser($actor)->allows('approve', $project);
        $canSubmit = $authorized
            && ! $terminal
            && $assessment->status === QaAssessment::STATUS_COMPLETED
            && $assessment->target_branch === 'develop'
            && $ticket instanceof RoadmapTask
            && $ticket->status->value === 'for_qa';
        $allowedActions = $canSubmit
            ? $this->decisionPolicy->allowedActions($assessment)
            : [];

        return [
            'submissionUrl' => route(
                'organizations.projects.quality-assurance.decisions.store',
                [
                    'organization' => $project->organization,
                    'project' => $project,
                    'assessment' => $assessment,
                ],
            ),
            'expectedAssessmentFingerprint' => $assessment
                ->canonical_assessment_fingerprint,
            'allowedActions' => array_map(
                static fn (MergeDecisionAction $action): string => $action->value,
                $allowedActions,
            ),
            'reasonRequiredActions' => array_values(array_map(
                static fn (MergeDecisionAction $action): string => $action->value,
                array_filter(
                    $allowedActions,
                    fn (MergeDecisionAction $action): bool => $this->decisionPolicy
                        ->reasonRequiredFor($action),
                ),
            )),
            'terminal' => $terminal,
            'canSubmit' => $canSubmit,
            'latestDecision' => $latestDecision === null
                ? null
                : [
                    'action' => $latestDecision->action->value,
                    'reason' => $latestDecision->reason,
                    'decidedAt' => $latestDecision
                        ->decided_at
                        ->toIso8601String(),
                    'ticketStatusAfter' => $latestDecision
                        ->ticket_status_after,
                    'terminal' => $terminal,
                    'simulated' => $latestDecision->simulated,
                    'actualState' => $latestDecision->actual_state,
                ],
        ];
    }

    /**
     * Normalize persisted findings for the presentation layer.
     *
     * @param  list<array<string, mixed>>|null  $findings
     * @return list<array<string, mixed>>
     */
    private function normalizeFindings(?array $findings): array
    {
        $normalized = [];

        foreach ($findings ?? [] as $finding) {
            $normalized[] = [
                'code' => $this->stringValue($finding, 'code', 'unknown'),
                'dimension' => $this->stringValue(
                    $finding,
                    'dimension',
                    'unknown',
                ),
                'severity' => $this->stringValue(
                    $finding,
                    'severity',
                    'unknown',
                ),
                'blocking' => ($finding['blocking'] ?? false) === true,
                'summary' => $this->stringValue($finding, 'summary'),
                'impact' => $this->stringValue($finding, 'impact'),
                'mitigation' => $this->stringValue($finding, 'mitigation'),
                'evidenceIds' => $this->stringList(
                    $finding['evidence_ids'] ?? null,
                ),
            ];
        }

        return $normalized;
    }

    /**
     * Normalize persisted merge risks for the presentation layer.
     *
     * @param  list<array<string, mixed>>|null  $risks
     * @return list<array<string, mixed>>
     */
    private function normalizeMergeRisks(?array $risks): array
    {
        $normalized = [];

        foreach ($risks ?? [] as $risk) {
            $normalized[] = [
                'code' => $this->stringValue($risk, 'code', 'unknown'),
                'level' => $this->stringValue($risk, 'level', 'unknown'),
                'summary' => $this->stringValue($risk, 'summary'),
                'impact' => $this->stringValue($risk, 'impact'),
                'mitigation' => $this->stringValue($risk, 'mitigation'),
                'evidenceIds' => $this->stringList(
                    $risk['evidence_ids'] ?? null,
                ),
            ];
        }

        return $normalized;
    }

    /**
     * Collect all report, finding, and risk evidence references.
     *
     * @return list<string>
     */
    private function referencedEvidenceIds(
        QaAssessment $assessment,
    ): array {
        $evidenceIds = $this->stringList($assessment->evidence_ids);

        foreach ($assessment->unresolved_findings ?? [] as $finding) {
            $evidenceIds = [
                ...$evidenceIds,
                ...$this->stringList($finding['evidence_ids'] ?? null),
            ];
        }

        foreach ($assessment->merge_risks ?? [] as $risk) {
            $evidenceIds = [
                ...$evidenceIds,
                ...$this->stringList($risk['evidence_ids'] ?? null),
            ];
        }

        return array_values(array_unique($evidenceIds));
    }

    /**
     * Load referenced evidence through the current project boundary only.
     *
     * @param  list<string>  $evidenceIds
     * @return Collection<string, Evidence>
     */
    private function evidenceRecords(
        int $projectId,
        array $evidenceIds,
    ): Collection {
        if ($evidenceIds === []) {
            return collect();
        }

        /** @var Collection<string, Evidence> $records */
        $records = Evidence::query()
            ->forProject($projectId)
            ->whereIn('id', $evidenceIds)
            ->get()
            ->keyBy(static fn (Evidence $evidence): string => $evidence->id);

        return $records;
    }

    /**
     * Serialize one evidence reference or a safe missing placeholder.
     *
     * @return array<string, mixed>
     */
    private function serializeEvidenceReference(
        string $evidenceId,
        mixed $evidence,
        CarbonImmutable $asOf,
    ): array {
        if (! $evidence instanceof Evidence) {
            return [
                'id' => $evidenceId,
                'available' => false,
                'classification' => 'missing',
                'state' => 'missing',
                'verified' => false,
                'stale' => false,
                'provider' => null,
                'sourceReference' => null,
                'claims' => [],
                'verifiedAt' => null,
                'expiresAt' => null,
                'reasonCode' => 'evidence.missing',
            ];
        }

        $state = $evidence->displayStateAt($asOf);

        return [
            'id' => $evidence->id,
            'available' => true,
            'classification' => $evidence->classification->value,
            'state' => $state,
            'verified' => $state === 'verified',
            'stale' => $state === 'stale',
            'provider' => $evidence->provider,
            'sourceReference' => $evidence->source_reference,
            'claims' => $this->stringList($evidence->claims),
            'verifiedAt' => $evidence->verified_at?->toIso8601String(),
            'expiresAt' => $evidence->expires_at?->toIso8601String(),
            'reasonCode' => $this->evidenceReasonCode($evidence, $state),
        ];
    }

    /**
     * Summarize current evidence state for decision presentation.
     *
     * @param  list<array<string, mixed>>  $evidenceReferences
     * @return array{total: int, verified: int, stale: int, missing: int, unverified: int, allCurrentlyVerified: bool}
     */
    private function summarizeEvidence(array $evidenceReferences): array
    {
        $total = count($evidenceReferences);
        $verified = 0;
        $stale = 0;
        $missing = 0;

        foreach ($evidenceReferences as $reference) {
            match ($reference['state'] ?? null) {
                'verified' => $verified++,
                'stale' => $stale++,
                'missing' => $missing++,
                default => null,
            };
        }

        return [
            'total' => $total,
            'verified' => $verified,
            'stale' => $stale,
            'missing' => $missing,
            'unverified' => $total - $verified - $stale - $missing,
            'allCurrentlyVerified' => $total > 0 && $verified === $total,
        ];
    }

    /**
     * Return a stable explanation code for one display state.
     */
    private function evidenceReasonCode(Evidence $evidence, string $state): string
    {
        if ($state === 'unverified' && $evidence->isVerified()) {
            return 'evidence.verification_timestamp_missing';
        }

        return match ($state) {
            'verified' => 'evidence.currently_verified',
            'stale' => 'evidence.expired',
            'simulated' => 'evidence.simulated',
            'rejected' => 'evidence.rejected',
            default => 'evidence.not_verified',
        };
    }

    /**
     * Read one non-empty string from a mixed persisted payload.
     *
     * @param  array<string, mixed>  $data
     */
    private function stringValue(
        array $data,
        string $key,
        string $fallback = '',
    ): string {
        $value = $data[$key] ?? null;

        return is_string($value) && $value !== ''
            ? $value
            : $fallback;
    }

    /**
     * Normalize a mixed value into unique non-empty strings.
     *
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return [];
        }

        $strings = array_values(array_filter(
            $value,
            static fn (mixed $item): bool => is_string($item)
                && $item !== '',
        ));

        return array_values(array_unique($strings));
    }
}
