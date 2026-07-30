<?php

declare(strict_types=1);

use App\Application\QualityAssurance\Data\QaAssessmentResult;
use App\Application\QualityAssurance\Data\QaFinding;
use App\Application\QualityAssurance\Data\QualityAssuranceExecutionRequest;
use App\Application\QualityAssurance\QaAssessmentValidator;
use App\Domain\QualityAssurance\QaDecision;
use App\Infrastructure\QualityAssurance\QaScenarioCatalog;

/**
 * Build one immutable provider request for catalog-focused unit tests.
 */
function aios107QualityAssuranceRequest(
    string $scenario,
    int $seed = 107,
): QualityAssuranceExecutionRequest {
    return new QualityAssuranceExecutionRequest(
        organizationId: 1,
        projectId: 10,
        roadmapId: 20,
        roadmapTaskId: 30,
        ticketId: 'AIOS-107',
        reviewExecutionId: '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        reviewAttemptId: 40,
        implementationExecutionId: '01ARZ3NDEKTSV4RRFFQ69G5FAW',
        implementationAttemptId: 50,
        contextSnapshotId: 60,
        contextFingerprint: str_repeat('a', 64),
        ticketObjective: 'Implement the deterministic QA scenario catalog.',
        includedScope: [
            'Layer 3 deterministic simulation scenarios.',
        ],
        excludedScope: [
            'Real repository review.',
            'Real merge authorization.',
        ],
        acceptanceCriteria: [
            'Required QA scenarios are deterministic.',
        ],
        requiredEvidence: [
            'Automated scenario catalog tests.',
        ],
        ticketRisk: 'high',
        targetBranch: 'develop',
        implementationLogicalRole: 'backend_engineer',
        reviewLogicalRole: 'qa_engineer',
        implementationArtifacts: [
            [
                'artifact_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAX',
                'artifact_type' => 'synthetic_pull_request',
                'actual_state' => 'unverified',
            ],
        ],
        evidenceIds: [
            '01ARZ3NDEKTSV4RRFFQ69G5FAY',
            '01ARZ3NDEKTSV4RRFFQ69G5FAZ',
        ],
        requestedReasoning: 'high',
        effectiveReasoning: 'high',
        reasoningResolutionSource: 'layer_3_final_qa_policy',
        providerPolicy: [
            'allowed' => [
                'simulation',
            ],
        ],
        simulationScenario: $scenario,
        deterministicSeed: $seed,
    );
}

test(
    'QA scenario catalog returns deterministic canonical decisions',
    function (
        string $scenario,
        QaDecision $expectedDecision,
        bool $expectsBlockingFinding,
    ): void {
        $validator = new QaAssessmentValidator;
        $catalog = new QaScenarioCatalog($validator);

        $request = aios107QualityAssuranceRequest($scenario);

        $first = $catalog->resolve($request);
        $second = $catalog->resolve($request);

        $hasBlockingFinding = array_any(
            $first->unresolvedFindings,
            static fn (QaFinding $finding): bool => $finding->blocking,
        );

        expect($first->decision)
            ->toBe($expectedDecision)
            ->and($first->targetBranch)
            ->toBe('develop')
            ->and($first->canonicalAssessmentFingerprint)
            ->toMatch('/\A[0-9a-f]{64}\z/')
            ->and($first->toArray())
            ->toBe($second->toArray())
            ->and($hasBlockingFinding)
            ->toBe($expectsBlockingFinding);

        $validator->validateAssessment($first);
    },
)->with([
    'happy path' => [
        QaScenarioCatalog::HAPPY_PATH,
        QaDecision::HumanReviewRequired,
        false,
    ],
    'changes requested' => [
        QaScenarioCatalog::CHANGES_REQUESTED,
        QaDecision::ChangesRequested,
        true,
    ],
    'blocked' => [
        QaScenarioCatalog::BLOCKED,
        QaDecision::Blocked,
        true,
    ],
    'merge ready low risk' => [
        QaScenarioCatalog::MERGE_READY_LOW_RISK,
        QaDecision::MergeReady,
        false,
    ],
    'merge ready high risk' => [
        QaScenarioCatalog::MERGE_READY_HIGH_RISK,
        QaDecision::MergeReadyWithRisks,
        false,
    ],
    'human review required' => [
        QaScenarioCatalog::HUMAN_REVIEW_REQUIRED,
        QaDecision::HumanReviewRequired,
        false,
    ],
]);

test(
    'QA scenario catalog is seed stable and seed sensitive',
    function (): void {
        $catalog = new QaScenarioCatalog(
            new QaAssessmentValidator,
        );

        $first = $catalog->resolve(
            aios107QualityAssuranceRequest(
                QaScenarioCatalog::MERGE_READY_HIGH_RISK,
                107,
            ),
        );

        $same = $catalog->resolve(
            aios107QualityAssuranceRequest(
                QaScenarioCatalog::MERGE_READY_HIGH_RISK,
                107,
            ),
        );

        $differentSeed = $catalog->resolve(
            aios107QualityAssuranceRequest(
                QaScenarioCatalog::MERGE_READY_HIGH_RISK,
                108,
            ),
        );

        expect($first->canonicalAssessmentFingerprint)
            ->toBe($same->canonicalAssessmentFingerprint)
            ->not->toBe(
                $differentSeed->canonicalAssessmentFingerprint,
            );
    },
);

test(
    'QA scenario catalog rejects unsupported scenarios',
    function (): void {
        $catalog = new QaScenarioCatalog(
            new QaAssessmentValidator,
        );

        expect(
            fn (): QaAssessmentResult => $catalog->resolve(
                aios107QualityAssuranceRequest('unsupported'),
            ),
        )->toThrow(
            InvalidArgumentException::class,
            'Unsupported QA simulation scenario [unsupported].',
        );
    },
);
