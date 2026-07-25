<?php

declare(strict_types=1);

use App\Domain\Workflows\Exceptions\InvalidWorkflowTransition;
use App\Domain\Workflows\WorkflowStateMachine;

it('resolves a transition allowed from the current state', function (): void {
    $transition = (new WorkflowStateMachine)->resolve(
        definition: workflowStateMachineDefinitionFixture(),
        currentState: 'queued',
        transitionName: 'start',
    );

    expect($transition->name)
        ->toBe('start')
        ->and($transition->from)
        ->toBe('queued')
        ->and($transition->to)
        ->toBe('running')
        ->and($transition->guard)
        ->toBeNull();
});

it('rejects a transition from the wrong current state', function (): void {
    expect(
        fn () => (new WorkflowStateMachine)->resolve(
            definition: workflowStateMachineDefinitionFixture(),
            currentState: 'queued',
            transitionName: 'complete',
        ),
    )->toThrow(
        InvalidWorkflowTransition::class,
        'Workflow transition "complete" cannot run from state "queued"; expected "running".',
    );
});

it('rejects every transition from a terminal state', function (): void {
    expect(
        fn () => (new WorkflowStateMachine)->resolve(
            definition: workflowStateMachineDefinitionFixture(),
            currentState: 'completed',
            transitionName: 'start',
        ),
    )->toThrow(
        InvalidWorkflowTransition::class,
        'Workflow state "completed" is terminal and cannot transition.',
    );
});

it('rejects transition names absent from the definition', function (): void {
    expect(
        fn () => (new WorkflowStateMachine)->resolve(
            definition: workflowStateMachineDefinitionFixture(),
            currentState: 'queued',
            transitionName: 'unknown',
        ),
    )->toThrow(
        InvalidWorkflowTransition::class,
        'Workflow transition "unknown" is not defined.',
    );
});

/**
 * Build a valid workflow-definition document fixture.
 *
 * @return array{
 *     initial_state: string,
 *     states: list<string>,
 *     terminal_states: list<string>,
 *     transitions: list<array{
 *         name: string,
 *         from: string,
 *         to: string,
 *         guard: string|null
 *     }>
 * }
 */
function workflowStateMachineDefinitionFixture(): array
{
    return [
        'initial_state' => 'queued',
        'states' => [
            'completed',
            'failed',
            'queued',
            'running',
        ],
        'terminal_states' => [
            'completed',
            'failed',
        ],
        'transitions' => [
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
            [
                'name' => 'start',
                'from' => 'queued',
                'to' => 'running',
                'guard' => null,
            ],
        ],
    ];
}
