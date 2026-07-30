<?php

declare(strict_types=1);

use App\Application\QualityAssurance\BlockingFindingPolicy;
use App\Application\QualityAssurance\Data\QaAssessmentResult;
use App\Application\QualityAssurance\QaAssessmentValidator;
use App\Domain\QualityAssurance\QaDecision;
use App\Domain\QualityAssurance\QaFindingSeverity;
use App\Domain\QualityAssurance\QaImpactLevel;
use App\Domain\QualityAssurance\QaReviewDimension;
use App\Domain\QualityAssurance\QaReviewStatus;

/**
 * Build one canonical QA result for blocking-finding policy tests.
 */
function aios108Assessment(
    QaDecision $decision,
    bool $blocking,
    QaFindingSeverity $severity = QaFindingSeverity::High,
): QaAssessmentResult {
    $evidenceId = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
    $validator = new QaAssessmentValidator;

    $payload = [
        'schema_version' => QaAssessmentResult::SCHEMA_VERSION,
        'decision' => $decision->value,
        'confidence' => 0.9,
        'target_branch' => 'develop',
        'ticket_scope_satisfied' => true,
        'acceptance_criteria_verified' => true,
        'ci_status' => QaReviewStatus::Passed->value,
        'test_status' => QaReviewStatus::Passed->value,
        'architecture_status' => QaReviewStatus::Passed->value,
        'security_status' => QaReviewStatus::Passed->value,
        'database_impact' => QaImpactLevel::None->value,
        'performance_impact' => QaImpactLevel::Low->value,
        'regression_risk' => QaImpactLevel::Low->value,
        'rollback_complexity' => QaImpactLevel::Low->value,
        'unresolved_findings' => [
            [
                'code' => 'QA-BLOCKING-001',
                'dimension' => QaReviewDimension::FunctionalCorrectness->value,
                'severity' => $severity->value,
                'blocking' => $blocking,
                'summary' => 'An unresolved QA finding remains.',
                'impact' => 'Approval could advance a known unresolved defect.',
                'mitigation' => 'Resolve the finding and repeat Layer 3 assessment.',
                'evidence_ids' => [$evidenceId],
            ],
        ],
        'merge_risks' => [],
        'recommendation' => 'Provider recommendation prepared for deterministic policy validation.',
        'evidence_ids' => [$evidenceId],
        'canonical_assessment_fingerprint' => '',
    ];

    $draft = QaAssessmentResult::fromArray($payload);
    $payload['canonical_assessment_fingerprint'] =
        $validator->fingerprint($draft);

    return QaAssessmentResult::fromArray($payload);
}

test(
    'approval recommendations are rejected when a blocking finding exists',
    function (QaDecision $decision): void {
        $assessment = aios108Assessment(
            decision: $decision,
            blocking: true,
        );

        expect(
            fn () => (new BlockingFindingPolicy)
                ->assertRecommendationAllowed($assessment),
        )->toThrow(
            InvalidArgumentException::class,
            sprintf(
                'QA decision [%s] cannot recommend approval while unresolved blocking findings exist: QA-BLOCKING-001.',
                $decision->value,
            ),
        );
    },
)->with([
    'merge ready' => [QaDecision::MergeReady],
    'merge ready with risks' => [QaDecision::MergeReadyWithRisks],
]);

test(
    'the explicit blocking flag is authoritative at every severity',
    function (QaFindingSeverity $severity): void {
        $assessment = aios108Assessment(
            decision: QaDecision::MergeReady,
            blocking: true,
            severity: $severity,
        );

        expect(
            fn () => (new BlockingFindingPolicy)
                ->assertRecommendationAllowed($assessment),
        )->toThrow(InvalidArgumentException::class);
    },
)->with([
    'info' => [QaFindingSeverity::Info],
    'low' => [QaFindingSeverity::Low],
    'medium' => [QaFindingSeverity::Medium],
    'high' => [QaFindingSeverity::High],
    'critical' => [QaFindingSeverity::Critical],
]);

test(
    'non-blocking findings do not prevent an approval recommendation',
    function (): void {
        $assessment = aios108Assessment(
            decision: QaDecision::MergeReady,
            blocking: false,
            severity: QaFindingSeverity::Critical,
        );

        (new BlockingFindingPolicy)
            ->assertRecommendationAllowed($assessment);

        expect($assessment->decision)->toBe(QaDecision::MergeReady);
    },
);

test(
    'non-approval dispositions may retain unresolved blocking findings',
    function (QaDecision $decision): void {
        $assessment = aios108Assessment(
            decision: $decision,
            blocking: true,
        );

        (new BlockingFindingPolicy)
            ->assertRecommendationAllowed($assessment);

        expect($assessment->decision)->toBe($decision);
    },
)->with([
    'changes requested' => [QaDecision::ChangesRequested],
    'blocked' => [QaDecision::Blocked],
    'human review required' => [QaDecision::HumanReviewRequired],
]);

test(
    'canonical QA validation applies the blocking-finding policy',
    function (): void {
        $assessment = aios108Assessment(
            decision: QaDecision::MergeReadyWithRisks,
            blocking: true,
        );

        expect(
            fn () => (new QaAssessmentValidator)
                ->validateAssessment($assessment),
        )->toThrow(
            InvalidArgumentException::class,
            'QA decision [merge_ready_with_risks] cannot recommend approval while unresolved blocking findings exist: QA-BLOCKING-001.',
        );
    },
);
