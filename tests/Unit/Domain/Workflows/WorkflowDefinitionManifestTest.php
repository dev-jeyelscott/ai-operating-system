<?php

declare(strict_types=1);

use App\Domain\Workflows\WorkflowDefinitionManifest;

it('produces the same checksum for equivalent unordered manifests', function (): void {
    $first = workflowDefinitionManifestFixture(
        states: ['queued', 'running', 'completed', 'failed'],
        terminalStates: ['completed', 'failed'],
        transitions: [
            [
                'name' => 'start',
                'from' => 'queued',
                'to' => 'running',
                'guard' => null,
            ],
            [
                'name' => 'complete',
                'from' => 'running',
                'to' => 'completed',
                'guard' => 'required_outputs_exist',
            ],
            [
                'name' => 'fail',
                'from' => 'running',
                'to' => 'failed',
                'guard' => null,
            ],
        ],
    );

    $second = workflowDefinitionManifestFixture(
        states: ['failed', 'completed', 'running', 'queued'],
        terminalStates: ['failed', 'completed'],
        transitions: [
            [
                'name' => 'fail',
                'from' => 'running',
                'to' => 'failed',
                'guard' => null,
            ],
            [
                'name' => 'complete',
                'from' => 'running',
                'to' => 'completed',
                'guard' => 'required_outputs_exist',
            ],
            [
                'name' => 'start',
                'from' => 'queued',
                'to' => 'running',
                'guard' => null,
            ],
        ],
    );

    expect($second->definition())
        ->toBe($first->definition())
        ->and($second->checksumSha256())
        ->toBe($first->checksumSha256());
});

it('rejects duplicate workflow states', function (): void {
    expect(
        fn (): WorkflowDefinitionManifest => workflowDefinitionManifestFixture(
            states: ['queued', 'running', 'running', 'completed'],
        ),
    )->toThrow(
        InvalidArgumentException::class,
        'Workflow states must be unique.',
    );
});

it('rejects transition references to unknown states', function (): void {
    expect(
        fn (): WorkflowDefinitionManifest => workflowDefinitionManifestFixture(
            transitions: [
                [
                    'name' => 'start',
                    'from' => 'queued',
                    'to' => 'missing',
                    'guard' => null,
                ],
            ],
        ),
    )->toThrow(
        InvalidArgumentException::class,
        'Transition "start" references unknown target state "missing".',
    );
});

it('rejects outgoing transitions from terminal states', function (): void {
    expect(
        fn (): WorkflowDefinitionManifest => workflowDefinitionManifestFixture(
            transitions: [
                [
                    'name' => 'restart',
                    'from' => 'completed',
                    'to' => 'queued',
                    'guard' => null,
                ],
            ],
        ),
    )->toThrow(
        InvalidArgumentException::class,
        'Terminal state "completed" cannot have outgoing transitions.',
    );
});

/**
 * Build one valid manifest and allow structural test overrides.
 *
 * @param  list<string>  $states
 * @param  list<string>  $terminalStates
 * @param  list<array{
 *     name: string,
 *     from: string,
 *     to: string,
 *     guard?: string|null
 * }>  $transitions
 */
function workflowDefinitionManifestFixture(
    array $states = ['queued', 'running', 'completed', 'failed'],
    array $terminalStates = ['completed', 'failed'],
    array $transitions = [
        [
            'name' => 'start',
            'from' => 'queued',
            'to' => 'running',
            'guard' => null,
        ],
        [
            'name' => 'complete',
            'from' => 'running',
            'to' => 'completed',
            'guard' => 'required_outputs_exist',
        ],
        [
            'name' => 'fail',
            'from' => 'running',
            'to' => 'failed',
            'guard' => null,
        ],
    ],
): WorkflowDefinitionManifest {
    return new WorkflowDefinitionManifest(
        definitionKey: 'project_delivery',
        version: 1,
        schemaVersion: 1,
        name: 'Project delivery workflow',
        description: 'Controls one deterministic project-delivery execution.',
        initialState: 'queued',
        states: $states,
        terminalStates: $terminalStates,
        transitions: $transitions,
    );
}
