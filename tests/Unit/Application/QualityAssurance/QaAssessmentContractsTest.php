<?php

declare(strict_types=1);

use App\Application\QualityAssurance\Data\QaAssessmentResult;
use App\Application\QualityAssurance\QaAssessmentValidator;

/**
 * Return deterministic evidence ULIDs used by the AIOS-103 contract fixtures.
 *
 * @return list<string>
 */
function aios103EvidenceIds(): array
{
    return [
        '01KYPAB5S2ETWGGMB4TFTVWX1H',
        '01KYPAB5S2ETWGGMB4TFTVWX1J',
    ];
}

/**
 * Build one valid QA assessment and recalculate its canonical fingerprint.
 *
 * @param  array<string, mixed>  $overrides
 */
function aios103Assessment(
    array $overrides = [],
): QaAssessmentResult {
    $evidenceIds = aios103EvidenceIds();

    $data = array_replace([
        'schema_version' => 1,
        'decision' => 'merge_ready_with_risks',
        'confidence' => 0.88,
        'target_branch' => 'develop',
        'ticket_scope_satisfied' => true,
        'acceptance_criteria_verified' => true,
        'ci_status' => 'passed',
        'test_status' => 'passed',
        'architecture_status' => 'passed',
        'security_status' => 'passed',
        'database_impact' => 'none',
        'performance_impact' => 'low',
        'regression_risk' => 'low',
        'rollback_complexity' => 'low',
        'unresolved_findings' => [[
            'code' => 'QA-OBS-001',
            'dimension' => 'observability',
            'severity' => 'medium',
            'blocking' => false,
            'summary' => 'The simulated execution does not include runtime telemetry.',
            'impact' => 'A real deployment would have reduced diagnostic evidence.',
            'mitigation' => 'Collect verified runtime and CI evidence before a real merge.',
            'evidence_ids' => [$evidenceIds[0]],
        ]],
        'merge_risks' => [[
            'code' => 'MR-REG-001',
            'level' => 'low',
            'summary' => 'Regression behavior remains simulated.',
            'impact' => 'A real repository could expose unobserved integration failures.',
            'mitigation' => 'Require verified CI and regression evidence before authorization.',
            'evidence_ids' => [$evidenceIds[1]],
        ]],
        'recommendation' => 'Approve only as a simulated merge recommendation after human confirmation.',
        'evidence_ids' => $evidenceIds,
        'canonical_assessment_fingerprint' => '',
    ], $overrides);

    $temporary = QaAssessmentResult::fromArray($data);

    $data['canonical_assessment_fingerprint'] =
        (new QaAssessmentValidator)->fingerprint($temporary);

    return QaAssessmentResult::fromArray($data);
}

test(
    'valid QA assessment round trips with a stable canonical schema',
    function (): void {
        $validator = new QaAssessmentValidator;
        $assessment = aios103Assessment();

        $validator->validateAssessment($assessment);

        expect(
            QaAssessmentResult::fromArray(
                $assessment->toArray(),
            )->toArray(),
        )->toBe($assessment->toArray())
            ->and(
                $validator->fingerprint($assessment),
            )->toBe($assessment->canonicalAssessmentFingerprint)
            ->and(
                $validator->canonicalJson([
                    'b' => 2,
                    'a' => 1,
                ]),
            )->toBe('{"a":1,"b":2}')
            ->and(
                array_keys($assessment->toArray()),
            )->toBe([
                'schema_version',
                'decision',
                'confidence',
                'target_branch',
                'ticket_scope_satisfied',
                'acceptance_criteria_verified',
                'ci_status',
                'test_status',
                'architecture_status',
                'security_status',
                'database_impact',
                'performance_impact',
                'regression_risk',
                'rollback_complexity',
                'unresolved_findings',
                'merge_risks',
                'recommendation',
                'evidence_ids',
                'canonical_assessment_fingerprint',
            ]);
    },
);

test(
    'assessment rejects unsupported versions invalid confidence and wrong targets',
    function (array $changes): void {
        $assessment = aios103Assessment($changes);

        expect(
            fn () => (new QaAssessmentValidator)
                ->validateAssessment($assessment),
        )->toThrow(InvalidArgumentException::class);
    },
)->with([
    'schema' => [
        ['schema_version' => 2],
    ],
    'negative confidence' => [
        ['confidence' => -0.01],
    ],
    'oversized confidence' => [
        ['confidence' => 1.01],
    ],
    'main target' => [
        ['target_branch' => 'main'],
    ],
]);

test(
    'assessment rejects malformed scalar nested and enum fields',
    function (): void {
        $data = aios103Assessment()->toArray();
        $data['confidence'] = '0.88';

        expect(
            fn () => QaAssessmentResult::fromArray($data),
        )->toThrow(InvalidArgumentException::class);

        $data = aios103Assessment()->toArray();
        $data['unresolved_findings'][0]['blocking'] = 1;

        expect(
            fn () => QaAssessmentResult::fromArray($data),
        )->toThrow(InvalidArgumentException::class);

        $data = aios103Assessment()->toArray();
        $data['decision'] = 'approved';

        expect(
            fn () => QaAssessmentResult::fromArray($data),
        )->toThrow(ValueError::class);
    },
);

test(
    'assessment rejects invalid duplicate and undeclared evidence references',
    function (array $changes): void {
        $assessment = aios103Assessment($changes);

        expect(
            fn () => (new QaAssessmentValidator)
                ->validateAssessment($assessment),
        )->toThrow(InvalidArgumentException::class);
    },
)->with([
    'invalid evidence id' => [[
        'evidence_ids' => [
            'not-a-ulid',
        ],
    ]],
    'duplicate evidence id' => [[
        'evidence_ids' => [
            '01KYPAB5S2ETWGGMB4TFTVWX1H',
            '01KYPAB5S2ETWGGMB4TFTVWX1H',
        ],
    ]],
    'undeclared finding evidence' => [[
        'unresolved_findings' => [[
            'code' => 'QA-OBS-001',
            'dimension' => 'observability',
            'severity' => 'medium',
            'blocking' => false,
            'summary' => 'Missing telemetry.',
            'impact' => 'Reduced diagnostics.',
            'mitigation' => 'Collect telemetry.',
            'evidence_ids' => [
                '01KYPAB5S2ETWGGMB4TFTVWX1K',
            ],
        ]],
    ]],
]);

test(
    'assessment rejects duplicate finding and merge-risk codes',
    function (): void {
        $finding =
            aios103Assessment()->toArray()['unresolved_findings'][0];

        $assessment = aios103Assessment([
            'unresolved_findings' => [
                $finding,
                $finding,
            ],
        ]);

        expect(
            fn () => (new QaAssessmentValidator)
                ->validateAssessment($assessment),
        )->toThrow(InvalidArgumentException::class);

        $risk =
            aios103Assessment()->toArray()['merge_risks'][0];

        $assessment = aios103Assessment([
            'merge_risks' => [
                $risk,
                $risk,
            ],
        ]);

        expect(
            fn () => (new QaAssessmentValidator)
                ->validateAssessment($assessment),
        )->toThrow(InvalidArgumentException::class);
    },
);

test(
    'assessment rejects secrets fingerprint drift and noncanonical JSON',
    function (): void {
        $validator = new QaAssessmentValidator;

        expect(
            fn () => $validator->validateAssessment(
                aios103Assessment([
                    'recommendation' => 'api_key=sk-1234567890abcdef',
                ]),
            ),
        )->toThrow(InvalidArgumentException::class);

        $data = aios103Assessment()->toArray();

        $data['canonical_assessment_fingerprint'] =
            str_repeat('0', 64);

        expect(
            fn () => $validator->validateAssessment(
                QaAssessmentResult::fromArray($data),
            ),
        )->toThrow(InvalidArgumentException::class)
            ->and(
                fn () => $validator->validateCanonicalPayload(
                    '{"b":2,"a":1}',
                ),
            )->toThrow(InvalidArgumentException::class);
    },
);
