<?php

declare(strict_types=1);

namespace App\Infrastructure\QualityAssurance;

use App\Application\QualityAssurance\Data\QaAssessmentResult;
use App\Application\QualityAssurance\Data\QualityAssuranceExecutionRequest;
use App\Application\QualityAssurance\QaAssessmentValidator;
use App\Domain\QualityAssurance\QaDecision;
use App\Domain\QualityAssurance\QaFindingSeverity;
use App\Domain\QualityAssurance\QaImpactLevel;
use App\Domain\QualityAssurance\QaReviewDimension;
use App\Domain\QualityAssurance\QaReviewStatus;
use InvalidArgumentException;

/**
 * Produces deterministic, evidence-referenced Layer 3 simulation scenarios.
 */
final readonly class QaScenarioCatalog
{
    public const string HAPPY_PATH = 'happy_path';

    public const string CHANGES_REQUESTED = 'changes_requested';

    public const string BLOCKED = 'blocked';

    public const string MERGE_READY_LOW_RISK = 'merge_ready_low_risk';

    public const string MERGE_READY_HIGH_RISK = 'merge_ready_high_risk';

    public const string HUMAN_REVIEW_REQUIRED = 'human_review_required';

    /** @var list<string> */
    public const array SUPPORTED_SCENARIOS = [
        self::HAPPY_PATH,
        self::CHANGES_REQUESTED,
        self::BLOCKED,
        self::MERGE_READY_LOW_RISK,
        self::MERGE_READY_HIGH_RISK,
        self::HUMAN_REVIEW_REQUIRED,
    ];

    public function __construct(
        private QaAssessmentValidator $validator,
    ) {}

    /**
     * Resolve one deterministic scenario into the canonical QA result contract.
     */
    public function resolve(
        QualityAssuranceExecutionRequest $request,
    ): QaAssessmentResult {
        $evidenceIds = array_values(array_unique($request->evidenceIds));
        sort($evidenceIds, SORT_STRING);

        if ($evidenceIds === []) {
            throw new InvalidArgumentException(
                'QA simulation requires Layer 2 evidence references.',
            );
        }

        $primaryEvidence = array_slice($evidenceIds, 0, 1);

        $scenario = $this->scenario(
            name: $request->simulationScenario,
            seed: $request->deterministicSeed,
            evidenceIds: $primaryEvidence,
        );

        $payload = [
            'schema_version' => QaAssessmentResult::SCHEMA_VERSION,
            ...$scenario,
            'target_branch' => $request->targetBranch,
            'evidence_ids' => $evidenceIds,
            'canonical_assessment_fingerprint' => '',
        ];

        $draft = QaAssessmentResult::fromArray($payload);

        $payload['canonical_assessment_fingerprint'] =
            $this->validator->fingerprint($draft);

        $result = QaAssessmentResult::fromArray($payload);

        $this->validator->validateAssessment($result);

        return $result;
    }

    /**
     * Build the scenario-specific QA fields while keeping the contract shape stable.
     *
     * @param  list<string>  $evidenceIds
     * @return array<string, mixed>
     */
    private function scenario(
        string $name,
        int $seed,
        array $evidenceIds,
    ): array {
        $base = [
            'decision' => QaDecision::HumanReviewRequired->value,
            'confidence' => $this->seededConfidence(0.82, $seed),
            'ticket_scope_satisfied' => true,
            'acceptance_criteria_verified' => false,
            'ci_status' => QaReviewStatus::Unverified->value,
            'test_status' => QaReviewStatus::Unverified->value,
            'architecture_status' => QaReviewStatus::Unverified->value,
            'security_status' => QaReviewStatus::Unverified->value,
            'database_impact' => QaImpactLevel::None->value,
            'performance_impact' => QaImpactLevel::Low->value,
            'regression_risk' => QaImpactLevel::Medium->value,
            'rollback_complexity' => QaImpactLevel::Low->value,
            'unresolved_findings' => [],
            'merge_risks' => [],
            'recommendation' => 'Human review is required before the simulated merge decision can advance.',
        ];

        return match ($name) {
            self::HAPPY_PATH => array_replace($base, [
                'unresolved_findings' => [
                    $this->finding(
                        code: 'QA-SIM-001',
                        dimension: QaReviewDimension::Ci,
                        severity: QaFindingSeverity::Medium,
                        blocking: false,
                        summary: 'CI and implementation evidence are simulated.',
                        impact: 'The assessment cannot establish verified repository or CI state.',
                        mitigation: 'Obtain observed and verified repository, test, and CI evidence before any real merge.',
                        evidenceIds: $evidenceIds,
                    ),
                ],
                'merge_risks' => [
                    $this->risk(
                        code: 'MERGE-SIM-001',
                        level: QaImpactLevel::Medium,
                        summary: 'Merge readiness is based on simulated evidence.',
                        impact: 'A real merge could contain defects not observable in the MVP simulation.',
                        mitigation: 'Keep the decision advisory and require human confirmation plus verified evidence.',
                        evidenceIds: $evidenceIds,
                    ),
                ],
                'recommendation' => 'Human review is required. Treat this Layer 3 result as simulated and obtain verified evidence before any real merge.',
            ]),

            self::CHANGES_REQUESTED => array_replace($base, [
                'decision' => QaDecision::ChangesRequested->value,
                'confidence' => $this->seededConfidence(0.91, $seed),
                'ci_status' => QaReviewStatus::Failed->value,
                'test_status' => QaReviewStatus::Failed->value,
                'architecture_status' => QaReviewStatus::Passed->value,
                'security_status' => QaReviewStatus::Passed->value,
                'regression_risk' => QaImpactLevel::High->value,
                'unresolved_findings' => [
                    $this->finding(
                        code: 'QA-FUNC-001',
                        dimension: QaReviewDimension::FunctionalCorrectness,
                        severity: QaFindingSeverity::High,
                        blocking: true,
                        summary: 'A required acceptance path fails in the simulated validation evidence.',
                        impact: 'The ticket cannot satisfy its approved acceptance criteria.',
                        mitigation: 'Return the ticket to Layer 2 and produce a corrected implementation attempt with fresh evidence.',
                        evidenceIds: $evidenceIds,
                    ),
                ],
                'merge_risks' => [
                    $this->risk(
                        code: 'MERGE-REG-001',
                        level: QaImpactLevel::High,
                        summary: 'The unresolved functional defect creates material regression risk.',
                        impact: 'Approving the change could expose users to a known broken path.',
                        mitigation: 'Do not approve the simulated merge decision until the blocking finding is resolved.',
                        evidenceIds: $evidenceIds,
                    ),
                ],
                'recommendation' => 'Request changes and create a new Layer 2 implementation attempt before QA is repeated.',
            ]),

            self::BLOCKED => array_replace($base, [
                'decision' => QaDecision::Blocked->value,
                'confidence' => $this->seededConfidence(0.96, $seed),
                'ticket_scope_satisfied' => false,
                'performance_impact' => QaImpactLevel::None->value,
                'regression_risk' => QaImpactLevel::Critical->value,
                'rollback_complexity' => QaImpactLevel::Medium->value,
                'unresolved_findings' => [
                    $this->finding(
                        code: 'QA-BLOCK-001',
                        dimension: QaReviewDimension::AcceptanceCriteria,
                        severity: QaFindingSeverity::Critical,
                        blocking: true,
                        summary: 'The available implementation evidence is insufficient to assess the approved acceptance criteria.',
                        impact: 'Layer 3 cannot make a defensible merge recommendation.',
                        mitigation: 'Resolve the evidence gap and restart QA from the same immutable ticket and implementation lineage.',
                        evidenceIds: $evidenceIds,
                    ),
                ],
                'merge_risks' => [
                    $this->risk(
                        code: 'MERGE-BLOCK-001',
                        level: QaImpactLevel::Critical,
                        summary: 'Merge risk cannot be bounded while required QA inputs remain incomplete.',
                        impact: 'Any merge decision would be unsupported by the required evidence.',
                        mitigation: 'Keep the ticket blocked until complete evidence is available.',
                        evidenceIds: $evidenceIds,
                    ),
                ],
                'recommendation' => 'Keep the ticket blocked and resolve the required evidence gap before another QA attempt.',
            ]),

            self::MERGE_READY_LOW_RISK => array_replace($base, [
                'decision' => QaDecision::MergeReady->value,
                'confidence' => $this->seededConfidence(0.94, $seed),
                'acceptance_criteria_verified' => true,
                'ci_status' => QaReviewStatus::Passed->value,
                'test_status' => QaReviewStatus::Passed->value,
                'architecture_status' => QaReviewStatus::Passed->value,
                'security_status' => QaReviewStatus::Passed->value,
                'regression_risk' => QaImpactLevel::Low->value,
                'unresolved_findings' => [],
                'merge_risks' => [
                    $this->risk(
                        code: 'MERGE-SIM-LOW-001',
                        level: QaImpactLevel::Low,
                        summary: 'The remaining risk is limited to the use of simulated evidence.',
                        impact: 'The MVP result cannot authorize a real repository merge.',
                        mitigation: 'Require verified evidence and an authorized gate before any real merge.',
                        evidenceIds: $evidenceIds,
                    ),
                ],
                'recommendation' => 'The simulated change is merge ready with low residual risk, but a real merge still requires verified evidence and an authorized gate.',
            ]),

            self::MERGE_READY_HIGH_RISK => array_replace($base, [
                'decision' => QaDecision::MergeReadyWithRisks->value,
                'confidence' => $this->seededConfidence(0.88, $seed),
                'acceptance_criteria_verified' => true,
                'ci_status' => QaReviewStatus::Passed->value,
                'test_status' => QaReviewStatus::Passed->value,
                'architecture_status' => QaReviewStatus::Passed->value,
                'security_status' => QaReviewStatus::Passed->value,
                'database_impact' => QaImpactLevel::High->value,
                'performance_impact' => QaImpactLevel::Medium->value,
                'regression_risk' => QaImpactLevel::High->value,
                'rollback_complexity' => QaImpactLevel::High->value,
                'unresolved_findings' => [
                    $this->finding(
                        code: 'QA-RISK-001',
                        dimension: QaReviewDimension::OperationalImpact,
                        severity: QaFindingSeverity::High,
                        blocking: false,
                        summary: 'The simulated change has broad operational and rollback impact.',
                        impact: 'A defect could affect critical workflows and require a complex recovery.',
                        mitigation: 'Require explicit human review of rollout, rollback, and monitoring evidence.',
                        evidenceIds: $evidenceIds,
                    ),
                ],
                'merge_risks' => [
                    $this->risk(
                        code: 'MERGE-HIGH-001',
                        level: QaImpactLevel::High,
                        summary: 'Residual regression and rollback risk exceeds the low-risk approval threshold.',
                        impact: 'Automatic approval would exceed the MVP autonomy policy.',
                        mitigation: 'Escalate to an authorized human and require verified operational evidence.',
                        evidenceIds: $evidenceIds,
                    ),
                ],
                'recommendation' => 'Escalate for explicit human review. The simulated result must not be approved automatically.',
            ]),

            self::HUMAN_REVIEW_REQUIRED => array_replace($base, [
                'confidence' => $this->seededConfidence(0.86, $seed),
                'database_impact' => QaImpactLevel::Low->value,
                'unresolved_findings' => [
                    $this->finding(
                        code: 'QA-HUMAN-001',
                        dimension: QaReviewDimension::AcceptanceCriteria,
                        severity: QaFindingSeverity::Medium,
                        blocking: false,
                        summary: 'The simulated evidence requires human interpretation before disposition.',
                        impact: 'The automated assessment cannot establish verified acceptance-criteria coverage.',
                        mitigation: 'Have an authorized reviewer inspect the ticket, evidence lineage, and residual risks.',
                        evidenceIds: $evidenceIds,
                    ),
                ],
                'merge_risks' => [
                    $this->risk(
                        code: 'MERGE-HUMAN-001',
                        level: QaImpactLevel::Medium,
                        summary: 'A consequential judgment remains outside deterministic simulation.',
                        impact: 'Proceeding without review could approve misunderstood scope or evidence.',
                        mitigation: 'Require an explicit human disposition before the workflow advances.',
                        evidenceIds: $evidenceIds,
                    ),
                ],
                'recommendation' => 'Require an authorized human review before approving, requesting changes, escalating, or deferring the simulated merge decision.',
            ]),

            default => throw new InvalidArgumentException(sprintf(
                'Unsupported QA simulation scenario [%s]. Supported scenarios: %s.',
                $name,
                implode(', ', self::SUPPORTED_SCENARIOS),
            )),
        };
    }

    /**
     * Build one canonical unresolved-finding payload.
     *
     * @param  list<string>  $evidenceIds
     * @return array<string, mixed>
     */
    private function finding(
        string $code,
        QaReviewDimension $dimension,
        QaFindingSeverity $severity,
        bool $blocking,
        string $summary,
        string $impact,
        string $mitigation,
        array $evidenceIds,
    ): array {
        return [
            'code' => $code,
            'dimension' => $dimension->value,
            'severity' => $severity->value,
            'blocking' => $blocking,
            'summary' => $summary,
            'impact' => $impact,
            'mitigation' => $mitigation,
            'evidence_ids' => $evidenceIds,
        ];
    }

    /**
     * Build one canonical merge-risk payload.
     *
     * @param  list<string>  $evidenceIds
     * @return array<string, mixed>
     */
    private function risk(
        string $code,
        QaImpactLevel $level,
        string $summary,
        string $impact,
        string $mitigation,
        array $evidenceIds,
    ): array {
        return [
            'code' => $code,
            'level' => $level->value,
            'summary' => $summary,
            'impact' => $impact,
            'mitigation' => $mitigation,
            'evidence_ids' => $evidenceIds,
        ];
    }

    /**
     * Derive a small, bounded confidence variation from the deterministic seed.
     */
    private function seededConfidence(float $base, int $seed): float
    {
        $offset = abs($seed % 7) / 1_000;

        return (float) min(0.99, round($base + $offset, 3));
    }
}
