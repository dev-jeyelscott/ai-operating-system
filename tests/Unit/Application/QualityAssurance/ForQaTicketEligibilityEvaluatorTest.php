<?php

declare(strict_types=1);

use App\Application\QualityAssurance\Data\ForQaArtifactFact;
use App\Application\QualityAssurance\Data\ForQaTicketEligibilityContext;
use App\Application\QualityAssurance\ForQaTicketEligibilityEvaluator;
use App\Domain\Executions\ExecutionAttemptStatus;
use App\Domain\Executions\ExecutionStatus;
use App\Domain\QualityAssurance\ForQaTicketIneligibilityReason;
use App\Domain\Tickets\TicketStatus;

/**
 * Create one valid artifact fact with optional lineage and metadata overrides.
 *
 * @param  array<string, mixed>|null  $metadata
 */
function aios105Artifact(
    string $type,
    string $executionId = '01KYPAB5S2ETWGGMB4TFTVWX1E',
    int $executionAttemptId = 501,
    bool $hasEvidence = true,
    ?array $metadata = null,
): ForQaArtifactFact {
    $defaultMetadata = match ($type) {
        'validation_result' => [
            'validations' => [[
                'command' => 'php artisan test',
                'status' => 'passed',
                'summary' => 'Automated tests passed.',
            ]],
        ],
        'synthetic_pull_request' => [
            'target_branch' => 'develop',
            'synthetic' => true,
            'evidence_still_required' => true,
        ],
        default => [],
    };

    return new ForQaArtifactFact(
        type: $type,
        executionId: $executionId,
        executionAttemptId: $executionAttemptId,
        hasEvidence: $hasEvidence,
        metadata: $metadata ?? $defaultMetadata,
    );
}

/**
 * Return every artifact required by the MVP Layer 2 contract.
 *
 * @return list<ForQaArtifactFact>
 */
function aios105CompleteArtifacts(): array
{
    return array_map(
        static fn (string $type): ForQaArtifactFact => aios105Artifact($type),
        [
            'implementation_plan',
            'changed_file_manifest',
            'validation_result',
            'synthetic_branch',
            'synthetic_commit',
            'synthetic_push',
            'synthetic_pull_request',
        ],
    );
}

/**
 * Create a valid default QA eligibility context with optional overrides.
 *
 * @param  list<ForQaArtifactFact>|null  $artifacts
 */
function aios105EligibilityContext(
    TicketStatus $ticketStatus = TicketStatus::ForQa,
    int $ticketProjectId = 10,
    int $roadmapContextSnapshotId = 100,
    ?string $implementationExecutionId =
        '01KYPAB5S2ETWGGMB4TFTVWX1E',
    ?int $implementationProjectId = 10,
    ?int $implementationContextSnapshotId = 100,
    ?string $implementationCapability =
        'development.simulation',
    ?ExecutionStatus $implementationExecutionStatus =
        ExecutionStatus::Completed,
    ?int $implementationAttemptId = 501,
    ?ExecutionAttemptStatus $implementationAttemptStatus =
        ExecutionAttemptStatus::Completed,
    bool $completionLeaseRecorded = true,
    ?array $artifacts = null,
): ForQaTicketEligibilityContext {
    return new ForQaTicketEligibilityContext(
        ticketStatus: $ticketStatus,
        ticketProjectId: $ticketProjectId,
        roadmapContextSnapshotId: $roadmapContextSnapshotId,
        implementationExecutionId: $implementationExecutionId,
        implementationProjectId: $implementationProjectId,
        implementationContextSnapshotId: $implementationContextSnapshotId,
        implementationCapability: $implementationCapability,
        implementationExecutionStatus: $implementationExecutionStatus,
        implementationAttemptId: $implementationAttemptId,
        implementationAttemptStatus: $implementationAttemptStatus,
        completionLeaseRecorded: $completionLeaseRecorded,
        artifacts: $artifacts ?? aios105CompleteArtifacts(),
    );
}

test(
    'complete Layer 2 execution with required artifacts is eligible for QA',
    function (): void {
        $result = (new ForQaTicketEligibilityEvaluator)->evaluate(
            aios105EligibilityContext(),
        );

        expect($result->isEligible())
            ->toBeTrue()
            ->and($result->reasons)
            ->toBe([])
            ->and($result->reasonValues())
            ->toBe([])
            ->and($result->missingArtifactTypes)
            ->toBe([])
            ->and($result->artifactTypesWithoutEvidence)
            ->toBe([]);
    },
);

test(
    'lifecycle and lineage gates fail in deterministic order',
    function (): void {
        $result = (new ForQaTicketEligibilityEvaluator)->evaluate(
            aios105EligibilityContext(
                ticketStatus: TicketStatus::Ready,
                implementationProjectId: 11,
                implementationContextSnapshotId: 101,
                implementationCapability: 'planning',
                implementationExecutionStatus: ExecutionStatus::Running,
                implementationAttemptStatus: ExecutionAttemptStatus::Running,
                completionLeaseRecorded: false,
            ),
        );

        expect($result->isEligible())
            ->toBeFalse()
            ->and($result->reasonValues())
            ->toBe([
                ForQaTicketIneligibilityReason::StatusNotForQa->value,
                ForQaTicketIneligibilityReason::ImplementationExecutionNotCompleted->value,
                ForQaTicketIneligibilityReason::ImplementationCapabilityInvalid->value,
                ForQaTicketIneligibilityReason::ProjectLineageMismatch->value,
                ForQaTicketIneligibilityReason::ContextSnapshotMismatch->value,
                ForQaTicketIneligibilityReason::ImplementationAttemptNotCompleted->value,
                ForQaTicketIneligibilityReason::CompletionLeaseMissing->value,
            ]);
    },
);

test(
    'missing implementation execution and attempt fail closed',
    function (): void {
        $result = (new ForQaTicketEligibilityEvaluator)->evaluate(
            aios105EligibilityContext(
                implementationExecutionId: null,
                implementationProjectId: null,
                implementationContextSnapshotId: null,
                implementationCapability: null,
                implementationExecutionStatus: null,
                implementationAttemptId: null,
                implementationAttemptStatus: null,
                completionLeaseRecorded: false,
                artifacts: [],
            ),
        );

        expect($result->isEligible())
            ->toBeFalse()
            ->and($result->reasonValues())
            ->toBe([
                ForQaTicketIneligibilityReason::ImplementationExecutionMissing->value,
                ForQaTicketIneligibilityReason::ImplementationAttemptMissing->value,
                ForQaTicketIneligibilityReason::CompletionLeaseMissing->value,
                ForQaTicketIneligibilityReason::RequiredArtifactMissing->value,
            ])
            ->and($result->missingArtifactTypes)
            ->toBe([
                'implementation_plan',
                'changed_file_manifest',
                'validation_result',
                'synthetic_branch',
                'synthetic_commit',
                'synthetic_push',
                'synthetic_pull_request',
            ]);
    },
);

test(
    'missing required artifacts are reported by stable type',
    function (): void {
        $artifacts = array_values(array_filter(
            aios105CompleteArtifacts(),
            static fn (ForQaArtifactFact $artifact): bool => ! in_array(
                $artifact->type,
                [
                    'synthetic_push',
                    'synthetic_pull_request',
                ],
                true,
            ),
        ));

        $result = (new ForQaTicketEligibilityEvaluator)->evaluate(
            aios105EligibilityContext(artifacts: $artifacts),
        );

        expect($result->isEligible())
            ->toBeFalse()
            ->and($result->reasonValues())
            ->toBe([
                ForQaTicketIneligibilityReason::RequiredArtifactMissing->value,
            ])
            ->and($result->missingArtifactTypes)
            ->toBe([
                'synthetic_push',
                'synthetic_pull_request',
            ]);
    },
);

test(
    'artifact from another execution attempt is rejected',
    function (): void {
        $artifacts = array_map(
            static fn (
                ForQaArtifactFact $artifact,
            ): ForQaArtifactFact => $artifact->type
                === 'validation_result'
                    ? aios105Artifact(
                        type: 'validation_result',
                        executionAttemptId: 999,
                    )
                    : $artifact,
            aios105CompleteArtifacts(),
        );

        $result = (new ForQaTicketEligibilityEvaluator)->evaluate(
            aios105EligibilityContext(artifacts: $artifacts),
        );

        expect($result->isEligible())
            ->toBeFalse()
            ->and($result->reasonValues())
            ->toBe([
                ForQaTicketIneligibilityReason::ArtifactLineageMismatch->value,
            ]);
    },
);

test(
    'required artifact without evidence is rejected',
    function (): void {
        $artifacts = array_map(
            static fn (
                ForQaArtifactFact $artifact,
            ): ForQaArtifactFact => $artifact->type
                === 'changed_file_manifest'
                    ? aios105Artifact(
                        type: 'changed_file_manifest',
                        hasEvidence: false,
                    )
                    : $artifact,
            aios105CompleteArtifacts(),
        );

        $result = (new ForQaTicketEligibilityEvaluator)->evaluate(
            aios105EligibilityContext(artifacts: $artifacts),
        );

        expect($result->isEligible())
            ->toBeFalse()
            ->and($result->reasonValues())
            ->toBe([
                ForQaTicketIneligibilityReason::ArtifactEvidenceMissing->value,
            ])
            ->and($result->artifactTypesWithoutEvidence)
            ->toBe([
                'changed_file_manifest',
            ]);
    },
);

test(
    'validation artifact must contain nonempty passed validations',
    function (array $metadata): void {
        $artifacts = array_map(
            static fn (
                ForQaArtifactFact $artifact,
            ): ForQaArtifactFact => $artifact->type
                === 'validation_result'
                    ? aios105Artifact(
                        type: 'validation_result',
                        metadata: $metadata,
                    )
                    : $artifact,
            aios105CompleteArtifacts(),
        );

        $result = (new ForQaTicketEligibilityEvaluator)->evaluate(
            aios105EligibilityContext(artifacts: $artifacts),
        );

        expect($result->isEligible())
            ->toBeFalse()
            ->and($result->reasonValues())
            ->toBe([
                ForQaTicketIneligibilityReason::ValidationNotPassed->value,
            ]);
    },
)->with([
    'failed command' => [[
        'validations' => [[
            'command' => 'php artisan test',
            'status' => 'failed',
            'summary' => 'A test failed.',
        ]],
    ]],
    'empty validation list' => [[
        'validations' => [],
    ]],
    'missing validation list' => [[]],
]);

test(
    'pull request must target develop',
    function (?string $targetBranch): void {
        $artifacts = array_map(
            static fn (
                ForQaArtifactFact $artifact,
            ): ForQaArtifactFact => $artifact->type
                === 'synthetic_pull_request'
                    ? aios105Artifact(
                        type: 'synthetic_pull_request',
                        metadata: [
                            'target_branch' => $targetBranch,
                            'synthetic' => true,
                            'evidence_still_required' => true,
                        ],
                    )
                    : $artifact,
            aios105CompleteArtifacts(),
        );

        $result = (new ForQaTicketEligibilityEvaluator)->evaluate(
            aios105EligibilityContext(artifacts: $artifacts),
        );

        expect($result->isEligible())
            ->toBeFalse()
            ->and($result->reasonValues())
            ->toBe([
                ForQaTicketIneligibilityReason::PullRequestTargetInvalid->value,
            ]);
    },
)->with([
    'main' => ['main'],
    'missing target' => [null],
]);

test(
    'eligibility context rejects malformed artifact collections',
    function (): void {
        expect(
            fn (): ForQaTicketEligibilityContext => new ForQaTicketEligibilityContext(
                ticketStatus: TicketStatus::ForQa,
                ticketProjectId: 10,
                roadmapContextSnapshotId: 100,
                implementationExecutionId: '01KYPAB5S2ETWGGMB4TFTVWX1E',
                implementationProjectId: 10,
                implementationContextSnapshotId: 100,
                implementationCapability: 'development.simulation',
                implementationExecutionStatus: ExecutionStatus::Completed,
                implementationAttemptId: 501,
                implementationAttemptStatus: ExecutionAttemptStatus::Completed,
                completionLeaseRecorded: true,
                artifacts: [
                    'artifact' => aios105Artifact(
                        'implementation_plan',
                    ),
                ],
            ),
        )->toThrow(
            InvalidArgumentException::class,
            'QA eligibility artifacts must be a list.',
        );

        expect(
            fn (): ForQaTicketEligibilityContext => new ForQaTicketEligibilityContext(
                ticketStatus: TicketStatus::ForQa,
                ticketProjectId: 10,
                roadmapContextSnapshotId: 100,
                implementationExecutionId: '01KYPAB5S2ETWGGMB4TFTVWX1E',
                implementationProjectId: 10,
                implementationContextSnapshotId: 100,
                implementationCapability: 'development.simulation',
                implementationExecutionStatus: ExecutionStatus::Completed,
                implementationAttemptId: 501,
                implementationAttemptStatus: ExecutionAttemptStatus::Completed,
                completionLeaseRecorded: true,
                artifacts: [
                    'implementation_plan',
                ],
            ),
        )->toThrow(
            InvalidArgumentException::class,
            'Every QA eligibility artifact must be a ForQaArtifactFact.',
        );
    },
);
