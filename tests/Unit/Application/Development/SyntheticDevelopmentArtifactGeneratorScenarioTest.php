<?php

declare(strict_types=1);

use App\Application\Development\Data\DevelopmentExecutionRequest;
use App\Application\Development\DevelopmentResultValidator;
use App\Application\Development\RepositoryExecutionPolicy;
use App\Application\Development\SyntheticDevelopmentArtifactGenerator;

it('rejects unknown development scenarios instead of treating them as success', function (): void {
    $validator = new DevelopmentResultValidator;
    $generator = new SyntheticDevelopmentArtifactGenerator(
        new RepositoryExecutionPolicy,
        $validator,
    );

    $request = developmentScenarioRequest('unknown_scenario');

    expect(fn () => $generator->generate($request))
        ->toThrow(
            InvalidArgumentException::class,
            'Unsupported development simulation scenario',
        );
});

it('produces a wrong-target result that deterministic validation rejects', function (): void {
    $validator = new DevelopmentResultValidator;
    $generator = new SyntheticDevelopmentArtifactGenerator(
        new RepositoryExecutionPolicy,
        $validator,
    );

    $result = $generator->generate(
        developmentScenarioRequest('wrong_pr_target'),
    );

    expect($result->targetBranch)->toBe('main')
        ->and($result->syntheticPullRequestResult?->targetBranch)
        ->toBe('main')
        ->and(fn () => $validator->validateResult($result))
        ->toThrow(
            InvalidArgumentException::class,
            'Synthetic pull request target must be develop',
        );
});

/**
 * Build one valid deterministic Layer 2 request for focused unit tests.
 */
function developmentScenarioRequest(
    string $scenario,
): DevelopmentExecutionRequest {
    return new DevelopmentExecutionRequest(
        organizationId: 1,
        projectId: 1,
        roadmapId: 1,
        ticketId: 'AIOS-151',
        executionId: '01K00000000000000000000001',
        attemptId: 1,
        attemptNumber: 1,
        leaseId: '01K00000000000000000000002',
        contextSnapshotId: 1,
        contextFingerprint: str_repeat('a', 64),
        ticketObjective: 'Exercise one deterministic development scenario.',
        includedScope: ['deterministic simulation'],
        excludedScope: ['real repository writes'],
        acceptanceCriteria: ['The scenario has a reproducible outcome.'],
        dependencyReferences: [],
        evidenceRequirements: ['simulated unverified result'],
        risk: 'medium',
        complexity: 3,
        repositoryProviderMetadata: [
            'provider' => 'simulation',
            'ticket_type' => 'feature',
        ],
        repositoryBaseReference: 'simulation://projects/1/base/develop',
        integrationTarget: 'develop',
        validationCommands: ['php artisan test'],
        requestedReasoning: 'medium',
        effectiveReasoning: 'medium',
        reasoningResolutionSource: 'test_fixture',
        providerPolicy: [
            'allowed_provider_ids' => ['simulation'],
            'fallback_order' => ['simulation'],
        ],
        budgetPolicy: [
            'limit_minor' => 5_000,
            'currency' => 'USD',
        ],
        retryPolicy: ['retry_limit' => 3],
        simulationScenario: $scenario,
        deterministicSeed: 151,
    );
}
