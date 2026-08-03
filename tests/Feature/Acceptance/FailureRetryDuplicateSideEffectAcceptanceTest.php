<?php

declare(strict_types=1);

use App\Application\Simulation\DeterministicScenarioCatalog;

/*
|--------------------------------------------------------------------------
| AIOS-155 deterministic acceptance cases
|--------------------------------------------------------------------------
|
| This dataset defines the release-blocking failure, retry, recovery, and
| replay scenarios owned by AIOS-155.
|
| It does not duplicate lower-level workflow tests. Instead, it establishes
| an explicit release contract that maps each acceptance requirement to the
| canonical deterministic scenario catalog.
|
*/

dataset('AIOS-155 deterministic acceptance scenarios', [
    'missing required document blocks project start' => [
        'missing_required_document',
        'planning',
        ['release_blocking', 'planning', 'blocker'],
        'blocks start',
    ],

    'conflicting documents require human resolution' => [
        'conflicting_documents',
        'planning',
        ['release_blocking', 'planning', 'human_decision'],
        'requires a human decision',
    ],

    'transient Notion failure retries only missing tickets' => [
        'notion_transient_failure',
        'notion_publication',
        ['release_blocking', 'notion', 'retry', 'idempotency'],
        'only missing tickets',
    ],

    'no workable ticket remains a valid idle state' => [
        'no_workable_ticket',
        'ticket_selection',
        ['release_blocking', 'ticket_selection', 'idle'],
        'without marking the workflow failed',
    ],

    'hard dependency keeps a ticket unclaimed' => [
        'dependency_blocked',
        'ticket_selection',
        ['release_blocking', 'ticket_selection', 'dependency'],
        'remains unclaimed',
    ],

    'failed development validation does not enter QA' => [
        'development_validation_failure',
        'development',
        ['release_blocking', 'development', 'validation', 'retry'],
        'does not transition to for qa',
    ],

    'provider timeout follows bounded retry policy' => [
        'provider_timeout',
        'development',
        ['release_blocking', 'development', 'timeout', 'retry'],
        'bounded retry',
    ],

    'wrong pull request target is rejected' => [
        'wrong_pr_target',
        'development',
        ['release_blocking', 'development', 'branch_policy', 'security'],
        'rejects main',
    ],

    'QA changes requested preserves the execution history' => [
        'qa_changes_requested',
        'quality_assurance',
        ['release_blocking', 'quality_assurance', 'changes_requested'],
        'changes-requested loop',
    ],

    'high-risk merge requires explicit review' => [
        'merge_ready_high_risk',
        'quality_assurance',
        [
            'release_blocking',
            'quality_assurance',
            'high_risk',
            'human_review',
        ],
        'explicit human review',
    ],

    'duplicate StartProject returns the original execution' => [
        'duplicate_start_project',
        'start_project',
        ['release_blocking', 'start_project', 'idempotency', 'replay'],
        'original execution is returned',
    ],

    'duplicate Notion publication does not create another page' => [
        'duplicate_notion_publication_retry',
        'notion_publication',
        ['release_blocking', 'notion', 'idempotency', 'replay'],
        'no duplicate notion page',
    ],
]);

/**
 * Verify that every AIOS-155 release scenario remains deterministic,
 * release-blocking, correctly classified, and reproducible by seed.
 */
test(
    'AIOS-155 keeps :dataset deterministic and release blocking',
    function (
        string $scenario,
        string $expectedCategory,
        array $requiredTags,
        string $expectedResultFragment,
    ): void {
        $catalog = app(DeterministicScenarioCatalog::class);

        $firstSelection = $catalog->select(
            scenario: $scenario,
            seed: 155,
        );

        $replayedSelection = $catalog->select(
            scenario: $scenario,
            seed: 155,
        );

        expect($replayedSelection)
            ->toBe($firstSelection)
            ->and($firstSelection['scenario'])
            ->toBe($scenario)
            ->and($firstSelection['category'])
            ->toBe($expectedCategory)
            ->and($firstSelection['seed'])
            ->toBe(155)
            ->and($firstSelection['fingerprint'])
            ->toMatch('/\A[0-9a-f]{64}\z/');

        foreach ($requiredTags as $requiredTag) {
            expect($firstSelection['tags'])
                ->toContain($requiredTag);
        }

        expect(mb_strtolower($firstSelection['expected_result']))
            ->toContain(mb_strtolower($expectedResultFragment));
    },
)->with('AIOS-155 deterministic acceptance scenarios')
    ->group('aios-155', 'acceptance');

/**
 * Verify that the complete release catalog remains complete and every
 * scenario receives a unique fingerprint for the selected acceptance seed.
 */
test(
    'AIOS-155 preserves the complete unique scenario catalog',
    function (): void {
        $catalog = app(DeterministicScenarioCatalog::class);
        $selections = $catalog->describeAll(seed: 155);

        $scenarioNames = array_column($selections, 'scenario');
        $fingerprints = array_column($selections, 'fingerprint');

        expect($selections)
            ->toHaveCount(14)
            ->and(array_unique($scenarioNames))
            ->toHaveCount(14)
            ->and(array_unique($fingerprints))
            ->toHaveCount(14);
    },
)->group('aios-155', 'acceptance');
