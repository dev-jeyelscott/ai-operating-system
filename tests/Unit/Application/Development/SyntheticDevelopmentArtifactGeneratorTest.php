<?php

declare(strict_types=1);

use App\Application\Development\Data\DevelopmentExecutionRequest;
use App\Application\Development\DevelopmentResultValidator;
use App\Application\Development\SyntheticDevelopmentArtifactGenerator;
use App\Domain\Development\Exceptions\DevelopmentProviderTimeout;

function aios097Request(int $seed = 97): DevelopmentExecutionRequest
{
    return DevelopmentExecutionRequest::fromArray([
        'schema_version' => 1, 'organization_id' => 1, 'project_id' => 2, 'roadmap_id' => 3, 'ticket_id' => 'AIOS-097',
        'execution_id' => '01KYPAB5S2ETWGGMB4TFTVWX1E', 'attempt_id' => 1, 'attempt_number' => 1,
        'lease_id' => '01KYPAB5S2ETWGGMB4TFTVWX1G', 'context_snapshot_id' => 5,
        'context_fingerprint' => hash('sha256', 'context'), 'ticket_objective' => 'Generate deterministic synthetic artifacts.',
        'included_scope' => ['Synthetic development output'], 'excluded_scope' => ['Real repository writes'],
        'acceptance_criteria' => ['All references are simulated'], 'dependency_references' => ['AIOS-096'],
        'evidence_requirements' => ['Real evidence later'], 'risk' => 'medium', 'complexity' => 5,
        'repository_provider_metadata' => ['provider' => 'simulation'], 'repository_base_reference' => 'simulation://projects/2/base/develop',
        'integration_target' => 'develop', 'validation_commands' => ['php artisan test'],
        'requested_reasoning' => 'medium', 'effective_reasoning' => 'medium', 'reasoning_resolution_source' => 'immutable_configuration_snapshot',
        'provider_policy' => ['allowed' => ['simulation']], 'budget_policy' => ['limit_minor' => 5000],
        'retry_policy' => ['retry_limit' => 3], 'simulation_scenario' => 'happy_path', 'deterministic_seed' => $seed,
    ]);
}

test('same input and seed produce byte identical synthetic output', function (): void {
    $generator = app(SyntheticDevelopmentArtifactGenerator::class);
    $validator = new DevelopmentResultValidator;
    $first = $generator->generate(aios097Request());
    $second = $generator->generate(aios097Request());

    $validator->validateResult($first);
    expect($validator->canonicalJson($first->toArray()))->toBe($validator->canonicalJson($second->toArray()));
});

test('different seeds change synthetic identities but preserve repository policy', function (): void {
    $generator = app(SyntheticDevelopmentArtifactGenerator::class);
    $first = $generator->generate(aios097Request(97));
    $second = $generator->generate(aios097Request(98));

    expect($first->syntheticCommitResult?->identifier)->not->toBe($second->syntheticCommitResult?->identifier)
        ->and($first->syntheticPushResult?->identifier)->not->toBe($second->syntheticPushResult?->identifier)
        ->and($first->syntheticPullRequestResult?->identifier)->not->toBe($second->syntheticPullRequestResult?->identifier)
        ->and($first->syntheticBranchResult->identifier)->toBe($second->syntheticBranchResult->identifier)
        ->and($first->targetBranch)->toBe('develop')->and($second->targetBranch)->toBe('develop');
});

test('every repository artifact is visibly simulated unverified and evidence incomplete', function (): void {
    $result = app(SyntheticDevelopmentArtifactGenerator::class)->generate(aios097Request());
    $artifacts = [
        $result->syntheticBranchResult, $result->syntheticCommitResult,
        $result->syntheticPushResult, $result->syntheticPullRequestResult,
    ];

    foreach ($artifacts as $artifact) {
        expect($artifact)->not->toBeNull()
            ->and($artifact?->reference)->toStartWith('simulation://')
            ->and($artifact?->reference)->not->toContain('github.com', 'http://', 'https://')
            ->and($artifact?->synthetic)->toBeTrue()
            ->and($artifact?->evidenceStillRequired)->toBeTrue();
    }

    expect($result->simulationClassification->value)->toBe('simulated')
        ->and($result->verificationClassification->value)->toBe('unverified')
        ->and($result->evidenceGaps)->not->toBeEmpty()
        ->and($result->syntheticCommitResult?->identifier)->toMatch('/\A[a-f0-9]{40}\z/');
});

test('changed paths are unique normalized project relative synthetic fixtures', function (): void {
    $result = app(SyntheticDevelopmentArtifactGenerator::class)->generate(aios097Request());
    $paths = array_map(static fn ($file): string => $file->path, $result->changedFiles);

    expect($paths)->toBe(array_values(array_unique($paths)));
    foreach ($paths as $path) {
        expect($path)->not->toStartWith('/')->not->toContain('..', '\\');
    }
});

test('DevelopmentValidationFailure omits successful repository artifacts and remains unverified', function (): void {
    $request = DevelopmentExecutionRequest::fromArray([
        ...aios097Request()->toArray(),
        'simulation_scenario' => 'development_validation_failure',
    ]);
    $result = app(SyntheticDevelopmentArtifactGenerator::class)->generate($request);

    (new DevelopmentResultValidator)->validateResult($result);
    expect($result->outcome->value)->toBe('validation_failed')
        ->and($result->syntheticCommitResult)->toBeNull()
        ->and($result->syntheticPushResult)->toBeNull()
        ->and($result->syntheticPullRequestResult)->toBeNull()
        ->and($result->validationResults[0]->status->value)->toBe('failed')
        ->and($result->verificationClassification->value)->toBe('unverified');
});

test('provider timeout scenario raises the typed timeout boundary', function (): void {
    $request = DevelopmentExecutionRequest::fromArray([
        ...aios097Request()->toArray(),
        'simulation_scenario' => 'provider_timeout_retry',
    ]);

    expect(fn () => app(SyntheticDevelopmentArtifactGenerator::class)->generate($request))
        ->toThrow(DevelopmentProviderTimeout::class);
});
