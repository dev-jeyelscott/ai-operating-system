<?php

declare(strict_types=1);

use App\Application\Development\Data\DevelopmentExecutionRequest;
use App\Application\Development\Data\DevelopmentExecutionResult;
use App\Application\Development\DevelopmentResultValidator;

function aios094Request(): DevelopmentExecutionRequest
{
    return DevelopmentExecutionRequest::fromArray([
        'schema_version' => 1, 'organization_id' => 1, 'project_id' => 2, 'roadmap_id' => 3, 'ticket_id' => 'AIOS-094',
        'execution_id' => '01KYPAB5S2ETWGGMB4TFTVWX1E', 'attempt_id' => 6, 'attempt_number' => 1,
        'lease_id' => '01KYPAB5S2ETWGGMB4TFTVWX1G', 'context_snapshot_id' => 5,
        'context_fingerprint' => hash('sha256', 'context'), 'ticket_objective' => 'Implement deterministic simulation.',
        'included_scope' => ['Application development boundary'], 'excluded_scope' => ['Real repository writes'],
        'acceptance_criteria' => ['Produces simulated artifacts'], 'dependency_references' => ['AIOS-093'],
        'evidence_requirements' => ['Automated tests'], 'risk' => 'high', 'complexity' => 8,
        'repository_provider_metadata' => ['provider' => 'simulation'], 'repository_base_reference' => 'simulation://base/develop',
        'integration_target' => 'develop', 'validation_commands' => ['php artisan test'],
        'requested_reasoning' => 'high', 'effective_reasoning' => 'high', 'reasoning_resolution_source' => 'project_policy',
        'provider_policy' => ['allowed' => ['simulation']], 'budget_policy' => ['limit_minor' => 5000],
        'retry_policy' => ['retry_limit' => 3], 'simulation_scenario' => 'happy_path', 'deterministic_seed' => 94,
    ]);
}

/** @param array<string, mixed> $overrides */
function aios094Result(array $overrides = []): DevelopmentExecutionResult
{
    $data = array_replace([
        'schema_version' => 1, 'provider_identifier' => 'simulation', 'capability' => 'development.simulation', 'outcome' => 'succeeded',
        'stage_results' => array_map(static fn (string $stage): array => ['stage' => $stage, 'status' => 'passed', 'summary' => "Simulated {$stage}."], ['plan', 'implementation', 'validation', 'commit', 'push', 'pull_request']),
        'implementation_plan' => ['Inspect scope', 'Generate synthetic changes'],
        'changed_files' => [['path' => 'app/Example.php', 'change_type' => 'modified', 'summary' => 'Simulated change.']],
        'diff_summary' => 'Simulated one-file change.',
        'validation_results' => [['command' => 'php artisan test', 'status' => 'passed', 'summary' => 'Simulated pass.']],
        'synthetic_branch_result' => ['kind' => 'branch', 'identifier' => 'feature/aios-094-contracts', 'reference' => 'simulation://projects/2/executions/example/branches/feature-aios-094', 'target_branch' => null, 'synthetic' => true, 'evidence_still_required' => true],
        'synthetic_commit_result' => ['kind' => 'commit', 'identifier' => str_repeat('a', 40), 'reference' => 'simulation://projects/2/executions/example/commits/'.str_repeat('a', 40), 'target_branch' => null, 'synthetic' => true, 'evidence_still_required' => true],
        'synthetic_push_result' => ['kind' => 'push', 'identifier' => 'push-94', 'reference' => 'simulation://projects/2/executions/example/pushes/push-94', 'target_branch' => null, 'synthetic' => true, 'evidence_still_required' => true],
        'synthetic_pull_request_result' => ['kind' => 'pull_request', 'identifier' => 'pr-94', 'reference' => 'simulation://projects/2/executions/example/pull-requests/pr-94', 'target_branch' => 'develop', 'synthetic' => true, 'evidence_still_required' => true],
        'target_branch' => 'develop', 'assumptions' => ['Simulation only'], 'confidence' => 0.9, 'risks' => ['No real QA'],
        'evidence_gaps' => ['Real repository evidence required'], 'simulation_classification' => 'simulated',
        'verification_classification' => 'unverified', 'retry_classification' => 'none',
        'recommended_next_action' => 'Collect real repository evidence.', 'canonical_result_fingerprint' => '',
    ], $overrides);
    $temporary = DevelopmentExecutionResult::fromArray($data);
    $data['canonical_result_fingerprint'] = (new DevelopmentResultValidator)->fingerprint($temporary);

    return DevelopmentExecutionResult::fromArray($data);
}

test('valid development request and result round trip canonically', function (): void {
    $validator = new DevelopmentResultValidator;
    $request = aios094Request();
    $result = aios094Result();

    $validator->validateRequest($request);
    $validator->validateResult($result);

    expect(DevelopmentExecutionRequest::fromArray($request->toArray())->toArray())->toBe($request->toArray())
        ->and(DevelopmentExecutionResult::fromArray($result->toArray())->toArray())->toBe($result->toArray())
        ->and($validator->fingerprint($result))->toBe($result->canonicalResultFingerprint)
        ->and($validator->canonicalJson(['b' => 2, 'a' => 1]))->toBe('{"a":1,"b":2}');
});

test('request rejects unsupported schema invalid identifiers targets and secrets', function (array $changes): void {
    $data = array_replace(aios094Request()->toArray(), $changes);
    $request = DevelopmentExecutionRequest::fromArray($data);

    expect(fn () => (new DevelopmentResultValidator)->validateRequest($request))->toThrow(InvalidArgumentException::class);
})->with([
    'schema' => [['schema_version' => 2]], 'numeric id' => [['project_id' => 0]],
    'ulid' => [['execution_id' => 'bad']], 'fingerprint' => [['context_fingerprint' => 'bad']],
    'target' => [['integration_target' => 'main']], 'confidence-sized objective' => [['ticket_objective' => str_repeat('x', 10_001)]],
    'secret' => [['repository_provider_metadata' => ['api_key' => 'sk-1234567890abcdef']]],
]);

test('result rejects unsafe paths duplicate files wrong targets and real references', function (array $changes): void {
    $result = aios094Result($changes);

    expect(fn () => (new DevelopmentResultValidator)->validateResult($result))->toThrow(InvalidArgumentException::class);
})->with([
    'absolute path' => [['changed_files' => [['path' => '/etc/passwd', 'change_type' => 'modified', 'summary' => 'Unsafe.']]]],
    'traversal' => [['changed_files' => [['path' => '../secret', 'change_type' => 'modified', 'summary' => 'Unsafe.']]]],
    'duplicate' => [['changed_files' => [
        ['path' => 'app/A.php', 'change_type' => 'added', 'summary' => 'One.'],
        ['path' => 'app/A.php', 'change_type' => 'modified', 'summary' => 'Two.'],
    ]]],
    'main target' => [['target_branch' => 'main']],
    'real reference' => [['synthetic_commit_result' => ['kind' => 'commit', 'identifier' => str_repeat('a', 40), 'reference' => 'https://github.com/example/repo/commit/'.str_repeat('a', 40), 'target_branch' => null, 'synthetic' => true, 'evidence_still_required' => true]]],
]);

test('result rejects stage contradictions fingerprint drift and noncanonical JSON', function (): void {
    $validator = new DevelopmentResultValidator;

    expect(fn () => $validator->validateResult(aios094Result([
        'stage_results' => [['stage' => 'plan', 'status' => 'passed', 'summary' => 'Only one.']],
    ])))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $validator->validateResult(aios094Result([
            'validation_results' => [['command' => 'test', 'status' => 'failed', 'summary' => 'Failed.']],
        ])))->toThrow(InvalidArgumentException::class);

    $drifted = aios094Result();
    $data = $drifted->toArray();
    $data['canonical_result_fingerprint'] = str_repeat('0', 64);
    expect(fn () => $validator->validateResult(DevelopmentExecutionResult::fromArray($data)))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $validator->validateCanonicalPayload('{"b":2,"a":1}'))->toThrow(InvalidArgumentException::class);
});

test('result rejects unsupported nested enum values', function (): void {
    $data = aios094Result()->toArray();
    $data['changed_files'][0]['change_type'] = 'renamed';

    expect(fn () => DevelopmentExecutionResult::fromArray($data))->toThrow(ValueError::class);
});

test('request rejects malformed nested list and policy values', function (): void {
    $data = aios094Request()->toArray();
    $data['included_scope'] = ['valid', 42];
    expect(fn () => DevelopmentExecutionRequest::fromArray($data))->toThrow(InvalidArgumentException::class);

    $data = aios094Request()->toArray();
    $data['provider_policy'] = ['allowed' => [['nested']]];
    expect(fn () => DevelopmentExecutionRequest::fromArray($data))->toThrow(InvalidArgumentException::class);
});
