<?php

declare(strict_types=1);

use App\Application\QualityAssurance\Layer3RoleIndependencePolicy;
use App\Domain\QualityAssurance\Layer3RoleIndependenceFailureReason;
use App\Models\Execution;

/**
 * Build one in-memory execution identity for isolated policy tests.
 */
function aios104Execution(
    string $id,
    int $projectId,
    ?int $contextSnapshotId,
    string $capability,
    ?string $logicalRole,
): Execution {
    $execution = new Execution;

    $execution->forceFill([
        'id' => $id,
        'project_id' => $projectId,
        'project_context_snapshot_id' => $contextSnapshotId,
        'capability' => $capability,
        'logical_role' => $logicalRole,
    ]);

    return $execution;
}

test(
    'separate simulation executions with valid layer roles are independent',
    function (): void {
        $implementation = aios104Execution(
            id: '01K1IMPLEMENTATION000000000001',
            projectId: 10,
            contextSnapshotId: 100,
            capability: 'development.simulation',
            logicalRole: 'backend_engineer',
        );

        $review = aios104Execution(
            id: '01K1QUALITYASSURANCE0000000002',
            projectId: 10,
            contextSnapshotId: 100,
            capability: 'quality_assurance.simulation',
            logicalRole: 'qa_engineer',
        );

        $decision = (new Layer3RoleIndependencePolicy)->evaluate(
            $implementation,
            $review,
        );

        expect($decision->allowed)->toBeTrue()
            ->and($decision->reason)->toBeNull();
    },
);

test(
    'the same logical execution cannot implement and review',
    function (): void {
        $implementation = aios104Execution(
            id: '01K1SHAREDEXECUTION00000000001',
            projectId: 10,
            contextSnapshotId: 100,
            capability: 'development.simulation',
            logicalRole: 'backend_engineer',
        );

        $review = aios104Execution(
            id: '01K1SHAREDEXECUTION00000000001',
            projectId: 10,
            contextSnapshotId: 100,
            capability: 'quality_assurance.simulation',
            logicalRole: 'qa_engineer',
        );

        $decision = (new Layer3RoleIndependencePolicy)->evaluate(
            $implementation,
            $review,
        );

        expect($decision->allowed)->toBeFalse()
            ->and($decision->reason)->toBe(
                Layer3RoleIndependenceFailureReason::SameLogicalExecution,
            );
    },
);

test(
    'missing execution identity fails closed',
    function (): void {
        $implementation = aios104Execution(
            id: '',
            projectId: 10,
            contextSnapshotId: 100,
            capability: 'development.simulation',
            logicalRole: 'backend_engineer',
        );

        $review = aios104Execution(
            id: '01K1QUALITYASSURANCE0000000002',
            projectId: 10,
            contextSnapshotId: 100,
            capability: 'quality_assurance.simulation',
            logicalRole: 'qa_engineer',
        );

        $decision = (new Layer3RoleIndependencePolicy)->evaluate(
            $implementation,
            $review,
        );

        expect($decision->allowed)->toBeFalse()
            ->and($decision->reason)->toBe(
                Layer3RoleIndependenceFailureReason::MissingExecutionIdentity,
            );
    },
);

test(
    'invalid layer capabilities fail closed',
    function (
        string $implementationCapability,
        string $reviewCapability,
        Layer3RoleIndependenceFailureReason $reason,
    ): void {
        $implementation = aios104Execution(
            id: '01K1IMPLEMENTATION000000000001',
            projectId: 10,
            contextSnapshotId: 100,
            capability: $implementationCapability,
            logicalRole: 'backend_engineer',
        );

        $review = aios104Execution(
            id: '01K1QUALITYASSURANCE0000000002',
            projectId: 10,
            contextSnapshotId: 100,
            capability: $reviewCapability,
            logicalRole: 'qa_engineer',
        );

        $decision = (new Layer3RoleIndependencePolicy)->evaluate(
            $implementation,
            $review,
        );

        expect($decision->allowed)->toBeFalse()
            ->and($decision->reason)->toBe($reason);
    },
)->with([
    'invalid implementation capability' => [
        'planning',
        'quality_assurance.simulation',
        Layer3RoleIndependenceFailureReason::InvalidImplementationCapability,
    ],
    'invalid review capability' => [
        'development.simulation',
        'planning',
        Layer3RoleIndependenceFailureReason::InvalidReviewCapability,
    ],
]);

test(
    'project and context lineage mismatches fail closed',
    function (
        int $reviewProjectId,
        ?int $implementationContextId,
        ?int $reviewContextId,
        Layer3RoleIndependenceFailureReason $reason,
    ): void {
        $implementation = aios104Execution(
            id: '01K1IMPLEMENTATION000000000001',
            projectId: 10,
            contextSnapshotId: $implementationContextId,
            capability: 'development.simulation',
            logicalRole: 'backend_engineer',
        );

        $review = aios104Execution(
            id: '01K1QUALITYASSURANCE0000000002',
            projectId: $reviewProjectId,
            contextSnapshotId: $reviewContextId,
            capability: 'quality_assurance.simulation',
            logicalRole: 'qa_engineer',
        );

        $decision = (new Layer3RoleIndependencePolicy)->evaluate(
            $implementation,
            $review,
        );

        expect($decision->allowed)->toBeFalse()
            ->and($decision->reason)->toBe($reason);
    },
)->with([
    'cross-project execution' => [
        11,
        100,
        100,
        Layer3RoleIndependenceFailureReason::CrossProjectLineage,
    ],
    'missing implementation context' => [
        10,
        null,
        100,
        Layer3RoleIndependenceFailureReason::MissingContextSnapshot,
    ],
    'missing review context' => [
        10,
        100,
        null,
        Layer3RoleIndependenceFailureReason::MissingContextSnapshot,
    ],
    'different context snapshots' => [
        10,
        100,
        101,
        Layer3RoleIndependenceFailureReason::ContextSnapshotMismatch,
    ],
]);

test(
    'blank or missing logical roles fail closed',
    function (
        ?string $implementationRole,
        ?string $reviewRole,
    ): void {
        $implementation = aios104Execution(
            id: '01K1IMPLEMENTATION000000000001',
            projectId: 10,
            contextSnapshotId: 100,
            capability: 'development.simulation',
            logicalRole: $implementationRole,
        );

        $review = aios104Execution(
            id: '01K1QUALITYASSURANCE0000000002',
            projectId: 10,
            contextSnapshotId: 100,
            capability: 'quality_assurance.simulation',
            logicalRole: $reviewRole,
        );

        $decision = (new Layer3RoleIndependencePolicy)->evaluate(
            $implementation,
            $review,
        );

        expect($decision->allowed)->toBeFalse()
            ->and($decision->reason)->toBe(
                Layer3RoleIndependenceFailureReason::MissingLogicalRole,
            );
    },
)->with([
    'missing implementation role' => [
        null,
        'qa_engineer',
    ],
    'blank implementation role' => [
        '   ',
        'qa_engineer',
    ],
    'missing review role' => [
        'backend_engineer',
        null,
    ],
    'blank review role' => [
        'backend_engineer',
        '   ',
    ],
]);
