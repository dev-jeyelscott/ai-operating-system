<?php

declare(strict_types=1);

namespace App\Infrastructure\Development;

use App\Application\Development\Contracts\DevelopmentExecutionProvider;
use App\Application\Development\Data\DevelopmentExecutionRequest;
use App\Application\Development\Data\DevelopmentExecutionResult;
use App\Application\Development\DevelopmentResultValidator;
use App\Application\Development\RepositoryExecutionPolicy;

final readonly class SimulationDevelopmentProvider implements DevelopmentExecutionProvider
{
    public function __construct(
        private RepositoryExecutionPolicy $repositoryPolicy,
        private DevelopmentResultValidator $validator,
    ) {}

    public function id(): string
    {
        return 'simulation';
    }

    public function supports(string $capability): bool
    {
        return $capability === 'development.simulation';
    }

    public function execute(DevelopmentExecutionRequest $request): DevelopmentExecutionResult
    {
        $branch = $this->repositoryPolicy->sourceBranch('feature', $request->ticketId, $request->ticketObjective);
        $root = "simulation://projects/{$request->projectId}/executions/{$request->executionId}";
        $commit = hash('sha1', $request->deterministicSeed.'|'.$request->executionId.'|'.$request->ticketId);
        $data = [
            'schema_version' => 1, 'provider_identifier' => 'simulation', 'capability' => 'development.simulation', 'outcome' => 'succeeded',
            'stage_results' => array_map(static fn (string $stage): array => ['stage' => $stage, 'status' => 'passed', 'summary' => "Simulated {$stage} completed."], ['plan', 'implementation', 'validation', 'commit', 'push', 'pull_request']),
            'implementation_plan' => ['Inspect immutable ticket scope.', 'Generate deterministic synthetic changes.', 'Record unverified validation output.'],
            'changed_files' => [['path' => 'app/Simulated/'.strtolower(str_replace('-', '', $request->ticketId)).'.php', 'change_type' => 'modified', 'summary' => 'Synthetic implementation change; no workspace was modified.']],
            'diff_summary' => 'Simulated diff only; no repository content was changed.',
            'validation_results' => array_map(static fn (string $command): array => ['command' => $command, 'status' => 'passed', 'summary' => 'Simulated validation pass; real execution remains required.'], $request->validationCommands),
            'synthetic_branch_result' => ['kind' => 'branch', 'identifier' => $branch, 'reference' => "{$root}/branches/".str_replace('/', '-', $branch), 'target_branch' => null, 'synthetic' => true, 'evidence_still_required' => true],
            'synthetic_commit_result' => ['kind' => 'commit', 'identifier' => $commit, 'reference' => "{$root}/commits/{$commit}", 'target_branch' => null, 'synthetic' => true, 'evidence_still_required' => true],
            'synthetic_push_result' => ['kind' => 'push', 'identifier' => substr(hash('sha256', $commit.'push'), 0, 24), 'reference' => "{$root}/pushes/".substr(hash('sha256', $commit.'push'), 0, 24), 'target_branch' => null, 'synthetic' => true, 'evidence_still_required' => true],
            'synthetic_pull_request_result' => ['kind' => 'pull_request', 'identifier' => substr(hash('sha256', $commit.'pr'), 0, 24), 'reference' => "{$root}/pull-requests/".substr(hash('sha256', $commit.'pr'), 0, 24), 'target_branch' => 'develop', 'synthetic' => true, 'evidence_still_required' => true],
            'target_branch' => 'develop', 'assumptions' => ['Simulation provider used.'], 'confidence' => 0.75,
            'risks' => ['No real source-code QA occurred.'], 'evidence_gaps' => ['Real repository and CI evidence remain required.'],
            'simulation_classification' => 'simulated', 'verification_classification' => 'unverified', 'retry_classification' => 'none',
            'recommended_next_action' => 'Run an authorized real implementation workflow in a later layer.', 'canonical_result_fingerprint' => '',
        ];
        $temporary = DevelopmentExecutionResult::fromArray($data);
        $data['canonical_result_fingerprint'] = $this->validator->fingerprint($temporary);

        return DevelopmentExecutionResult::fromArray($data);
    }
}
