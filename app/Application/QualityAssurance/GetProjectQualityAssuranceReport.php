<?php

declare(strict_types=1);

namespace App\Application\QualityAssurance;

use App\Models\Evidence;
use App\Models\ExecutionAttempt;
use App\Models\Project;
use App\Models\QaAssessment;
use App\Models\RoadmapTask;
use Illuminate\Support\Collection;

/**
 * Builds the latest project-scoped Layer 3 QA report read model.
 */
final readonly class GetProjectQualityAssuranceReport
{
    /**
     * Return the latest QA assessment without exposing raw provider metadata.
     *
     * @return array<string, mixed>
     */
    public function handle(int $organizationId, int $projectId): array
    {
        Project::query()
            ->where('organization_id', $organizationId)
            ->whereKey($projectId)
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
                'asOf' => now()->toIso8601String(),
                'assessment' => null,
            ];
        }

        $evidenceIds = $this->referencedEvidenceIds($assessment);
        $evidenceById = $this->evidenceRecords(
            projectId: $projectId,
            evidenceIds: $evidenceIds,
        );

        return [
            'asOf' => now()->toIso8601String(),
            'assessment' => $this->serializeAssessment(
                assessment: $assessment,
                evidenceIds: $evidenceIds,
                evidenceById: $evidenceById,
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
    ): array {
        $ticket = $assessment->ticket;
        $reviewAttempt = $assessment->reviewAttempt;
        $actualState = $reviewAttempt instanceof ExecutionAttempt
            ? ($reviewAttempt->actual_state ?? 'unverified')
            : 'unverified';

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
            'evidenceReferences' => array_map(
                fn (string $evidenceId): array => $this
                    ->serializeEvidenceReference(
                        evidenceId: $evidenceId,
                        evidence: $evidenceById->get($evidenceId),
                    ),
                $evidenceIds,
            ),
            'provenance' => [
                'isSimulated' => $reviewAttempt instanceof ExecutionAttempt
                    && $reviewAttempt->execution_provider === 'simulation',
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
    ): array {
        if (! $evidence instanceof Evidence) {
            return [
                'id' => $evidenceId,
                'available' => false,
                'classification' => 'missing',
                'verified' => false,
                'provider' => null,
                'sourceReference' => null,
                'claims' => [],
            ];
        }

        return [
            'id' => $evidence->id,
            'available' => true,
            'classification' => $evidence->classification->value,
            'verified' => $evidence->isVerified(),
            'provider' => $evidence->provider,
            'sourceReference' => $evidence->source_reference,
            'claims' => $this->stringList($evidence->claims),
        ];
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
