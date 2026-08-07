<?php

declare(strict_types=1);

namespace App\Application\Development;

use App\Application\Development\Data\DevelopmentExecutionRequest;
use App\Application\Development\Data\DevelopmentExecutionResult;
use App\Domain\Development\Exceptions\DevelopmentProviderTimeout;
use App\Domain\Executions\ExecutionCapability;
use InvalidArgumentException;

/**
 * Generates deterministic synthetic Layer 2 artifacts for supported scenarios.
 */
final readonly class SyntheticDevelopmentArtifactGenerator
{
    public const string HAPPY_PATH = 'happy_path';

    public const string VALIDATION_FAILURE = 'development_validation_failure';

    public const string PROVIDER_TIMEOUT_RETRY = 'provider_timeout_retry';

    public const string WRONG_PULL_REQUEST_TARGET = 'wrong_pr_target';

    /** @var list<string> */
    public const array SUPPORTED_SCENARIOS = [
        self::HAPPY_PATH,
        self::VALIDATION_FAILURE,
        self::PROVIDER_TIMEOUT_RETRY,
        self::WRONG_PULL_REQUEST_TARGET,
    ];

    /**
     * Inject repository policy and canonical result fingerprint services.
     */
    public function __construct(
        private RepositoryExecutionPolicy $repositoryPolicy,
        private DevelopmentResultValidator $validator,
    ) {}

    /**
     * Generate one supported synthetic result or fail closed.
     */
    public function generate(
        DevelopmentExecutionRequest $request,
    ): DevelopmentExecutionResult {
        if (! in_array(
            $request->simulationScenario,
            self::SUPPORTED_SCENARIOS,
            true,
        )) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported development simulation scenario [%s]. Supported scenarios: %s.',
                $request->simulationScenario,
                implode(', ', self::SUPPORTED_SCENARIOS),
            ));
        }

        if (
            $request->simulationScenario
            === self::PROVIDER_TIMEOUT_RETRY
        ) {
            throw new DevelopmentProviderTimeout(
                'The simulated development provider timed out.',
            );
        }

        $ticketType = $request
            ->repositoryProviderMetadata['ticket_type'] ?? null;

        if (! is_string($ticketType)) {
            throw new InvalidArgumentException(
                'Development repository ticket type is invalid.',
            );
        }

        $branch = $this->repositoryPolicy->sourceBranch(
            $ticketType,
            $request->ticketId,
            $request->ticketObjective,
        );

        $root = sprintf(
            'simulation://projects/%d/executions/%s',
            $request->projectId,
            $request->executionId,
        );

        $commit = hash('sha1', implode('|', [
            (string) $request->projectId,
            $request->ticketId,
            $request->executionId,
            (string) $request->attemptNumber,
            $request->repositoryBaseReference,
            $request->simulationScenario,
            (string) $request->deterministicSeed,
        ]));

        $push = substr(
            hash('sha256', $commit . '|push'),
            0,
            24,
        );

        $pullRequest = substr(
            hash('sha256', $commit . '|pull-request'),
            0,
            24,
        );

        $path = 'app/Simulated/' . strtolower(
            preg_replace(
                '/[^a-zA-Z0-9]/',
                '',
                $request->ticketId,
            ) ?? '',
        ) . '.php';

        $data = [
            'schema_version' => 1,
            'provider_identifier' => 'simulation',
            'capability' => ExecutionCapability::DevelopmentExecute->value,
            'outcome' => 'succeeded',
            'stage_results' => array_map(
                static fn(string $stage): array => [
                    'stage' => $stage,
                    'status' => 'passed',
                    'summary' => sprintf(
                        'Simulated %s completed; unverified evidence remains required.',
                        $stage,
                    ),
                ],
                [
                    'plan',
                    'implementation',
                    'validation',
                    'commit',
                    'push',
                    'pull_request',
                ],
            ),
            'implementation_plan' => [
                'Simulated plan: inspect immutable ticket scope.',
                'Simulated plan: generate deterministic synthetic changes.',
                'Simulated plan: record unverified validation output.',
            ],
            'changed_files' => [
                [
                    'path' => $path,
                    'change_type' => 'modified',
                    'summary' => 'Simulated change only; no workspace file was modified.',
                ],
            ],
            'diff_summary' => 'Simulated diff only; no repository content was changed.',
            'validation_results' => array_map(
                static fn(string $command): array => [
                    'command' => $command,
                    'status' => 'passed',
                    'summary' => 'Simulated validation pass; real command execution remains required.',
                ],
                $request->validationCommands,
            ),
            'synthetic_branch_result' => [
                'kind' => 'branch',
                'identifier' => $branch,
                'reference' => "{$root}/branches/{$branch}",
                'target_branch' => null,
                'synthetic' => true,
                'evidence_still_required' => true,
            ],
            'synthetic_commit_result' => [
                'kind' => 'commit',
                'identifier' => $commit,
                'reference' => "{$root}/commits/{$commit}",
                'target_branch' => null,
                'synthetic' => true,
                'evidence_still_required' => true,
            ],
            'synthetic_push_result' => [
                'kind' => 'push',
                'identifier' => $push,
                'reference' => "{$root}/pushes/{$push}",
                'target_branch' => null,
                'synthetic' => true,
                'evidence_still_required' => true,
            ],
            'synthetic_pull_request_result' => [
                'kind' => 'pull_request',
                'identifier' => $pullRequest,
                'reference' => "{$root}/pull-requests/{$pullRequest}",
                'target_branch' => 'develop',
                'synthetic' => true,
                'evidence_still_required' => true,
            ],
            'target_branch' => 'develop',
            'assumptions' => [
                'Simulation provider used; no repository access occurred.',
            ],
            'confidence' => 0.75,
            'risks' => [
                'No real source-code QA occurred.',
            ],
            'evidence_gaps' => [
                'Real repository, command, CI, and review evidence remain required.',
            ],
            'simulation_classification' => 'simulated',
            'verification_classification' => 'unverified',
            'retry_classification' => 'none',
            'recommended_next_action' => 'Collect authorized real repository evidence in a later layer.',
            'canonical_result_fingerprint' => '',
        ];

        if (
            $request->simulationScenario
            === self::VALIDATION_FAILURE
        ) {
            $data['outcome'] = 'validation_failed';
            $data['stage_results'] = [
                [
                    'stage' => 'plan',
                    'status' => 'passed',
                    'summary' => 'Simulated plan completed.',
                ],
                [
                    'stage' => 'implementation',
                    'status' => 'passed',
                    'summary' => 'Simulated implementation completed.',
                ],
                [
                    'stage' => 'validation',
                    'status' => 'failed',
                    'summary' => 'Simulated validation failed.',
                ],
                [
                    'stage' => 'commit',
                    'status' => 'skipped',
                    'summary' => 'Synthetic commit skipped after validation failure.',
                ],
                [
                    'stage' => 'push',
                    'status' => 'skipped',
                    'summary' => 'Synthetic push skipped after validation failure.',
                ],
                [
                    'stage' => 'pull_request',
                    'status' => 'skipped',
                    'summary' => 'Synthetic pull request skipped after validation failure.',
                ],
            ];

            $data['validation_results'] = array_map(
                static fn(string $command): array => [
                    'command' => $command,
                    'status' => 'failed',
                    'summary' => 'Simulated validation failure; no real command ran.',
                ],
                $request->validationCommands,
            );

            $data['synthetic_commit_result'] = null;
            $data['synthetic_push_result'] = null;
            $data['synthetic_pull_request_result'] = null;
            $data['retry_classification'] = 'validation';
            $data['recommended_next_action'] = 'Wait for the durable retry schedule before another attempt.';
        }

        if (
            $request->simulationScenario
            === self::WRONG_PULL_REQUEST_TARGET
        ) {
            /*
             * The wrong-target scenario cannot also be the validation-failure
             * scenario, so this artifact is guaranteed to contain the
             * deterministic pull-request array created above.
             */
            $data['synthetic_pull_request_result']['target_branch'] = 'main';
            $data['target_branch'] = 'main';
            $data['risks'] = [
                ...$data['risks'],
                'The synthetic pull request violates the develop-only target policy.',
            ];
            $data['recommended_next_action'] = 'Reject the provider result and record the branch-policy violation.';
        }

        $temporary = DevelopmentExecutionResult::fromArray($data);

        $data['canonical_result_fingerprint'] = $this->validator
            ->fingerprint($temporary);

        return DevelopmentExecutionResult::fromArray($data);
    }
}
