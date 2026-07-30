<?php

declare(strict_types=1);

namespace App\Infrastructure\QualityAssurance;

use App\Application\QualityAssurance\Contracts\QualityAssuranceExecutionProvider;
use App\Application\QualityAssurance\Data\MergeRisk;
use App\Application\QualityAssurance\Data\QaAssessmentResult;
use App\Application\QualityAssurance\Data\QaFinding;
use App\Application\QualityAssurance\Data\QualityAssuranceExecutionRequest;
use App\Application\QualityAssurance\QaAssessmentValidator;
use App\Domain\QualityAssurance\QaDecision;
use App\Domain\QualityAssurance\QaFindingSeverity;
use App\Domain\QualityAssurance\QaImpactLevel;
use App\Domain\QualityAssurance\QaReviewDimension;
use App\Domain\QualityAssurance\QaReviewStatus;
use InvalidArgumentException;

/**
 * Produces the minimal deterministic Layer 3 simulation result for AIOS-106.
 *
 * AIOS-107 will expand this provider with the complete scenario catalog.
 */
final readonly class SimulationQualityAssuranceProvider implements QualityAssuranceExecutionProvider
{
    public function __construct(
        private QaAssessmentValidator $validator,
    ) {}

    /**
     * Return the stable simulation provider identifier.
     */
    public function id(): string
    {
        return 'simulation';
    }

    /**
     * Support only the Layer 3 simulation capability.
     */
    public function supports(string $capability): bool
    {
        return $capability === 'quality_assurance.simulation';
    }

    /**
     * Produce a deterministic, evidence-referenced QA assessment.
     */
    public function execute(
        QualityAssuranceExecutionRequest $request,
    ): QaAssessmentResult {
        if ($request->simulationScenario !== 'happy_path') {
            throw new InvalidArgumentException(
                'AIOS-106 supports only the happy_path QA scenario. '
                .'Additional scenarios belong to AIOS-107.',
            );
        }

        $evidenceIds = array_values(array_unique(
            $request->evidenceIds,
        ));

        if ($evidenceIds === []) {
            throw new InvalidArgumentException(
                'QA simulation requires Layer 2 evidence references.',
            );
        }

        $primaryEvidence = array_slice($evidenceIds, 0, 1);

        $finding = new QaFinding(
            code: 'QA-SIM-001',
            dimension: QaReviewDimension::Ci,
            severity: QaFindingSeverity::Medium,
            blocking: false,
            summary: 'CI and implementation evidence are simulated.',
            impact: 'The assessment cannot establish verified repository or CI state.',
            mitigation: 'Obtain observed and verified repository, test, and CI evidence before any real merge.',
            evidenceIds: $primaryEvidence,
        );

        $risk = new MergeRisk(
            code: 'MERGE-SIM-001',
            level: QaImpactLevel::Medium,
            summary: 'Merge readiness is based on simulated evidence.',
            impact: 'A real merge could contain defects not observable in the MVP simulation.',
            mitigation: 'Keep the merge decision advisory and require human confirmation plus verified evidence.',
            evidenceIds: $primaryEvidence,
        );

        $draft = new QaAssessmentResult(
            decision: QaDecision::HumanReviewRequired,
            confidence: 0.82,
            targetBranch: $request->targetBranch,
            ticketScopeSatisfied: true,
            acceptanceCriteriaVerified: false,
            ciStatus: QaReviewStatus::Unverified,
            testStatus: QaReviewStatus::Unverified,
            architectureStatus: QaReviewStatus::Unverified,
            securityStatus: QaReviewStatus::Unverified,
            databaseImpact: QaImpactLevel::None,
            performanceImpact: QaImpactLevel::Low,
            regressionRisk: QaImpactLevel::Medium,
            rollbackComplexity: QaImpactLevel::Low,
            unresolvedFindings: [$finding],
            mergeRisks: [$risk],
            recommendation: 'Human review is required. Treat this Layer 3 result as simulated and obtain verified evidence before any real merge.',
            evidenceIds: $evidenceIds,
            canonicalAssessmentFingerprint: '',
        );

        return new QaAssessmentResult(
            decision: $draft->decision,
            confidence: $draft->confidence,
            targetBranch: $draft->targetBranch,
            ticketScopeSatisfied: $draft->ticketScopeSatisfied,
            acceptanceCriteriaVerified: $draft->acceptanceCriteriaVerified,
            ciStatus: $draft->ciStatus,
            testStatus: $draft->testStatus,
            architectureStatus: $draft->architectureStatus,
            securityStatus: $draft->securityStatus,
            databaseImpact: $draft->databaseImpact,
            performanceImpact: $draft->performanceImpact,
            regressionRisk: $draft->regressionRisk,
            rollbackComplexity: $draft->rollbackComplexity,
            unresolvedFindings: $draft->unresolvedFindings,
            mergeRisks: $draft->mergeRisks,
            recommendation: $draft->recommendation,
            evidenceIds: $draft->evidenceIds,
            canonicalAssessmentFingerprint: $this->validator->fingerprint($draft),
        );
    }
}
