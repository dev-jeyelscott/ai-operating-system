<?php

declare(strict_types=1);

use App\Application\Shared\Exceptions\ConflictException;
use App\Application\Workflows\RegisterWorkflowDefinition;
use App\Application\Workflows\ResolveWorkflowDefinition;
use App\Domain\Workflows\WorkflowDefinitionManifest;

it('stores and resolves exact immutable workflow-definition versions', function (): void {
    $register = app(RegisterWorkflowDefinition::class);
    $resolve = app(ResolveWorkflowDefinition::class);

    $versionOne = $register->handle(
        projectDeliveryWorkflowManifest(version: 1),
    );

    $versionTwo = $register->handle(
        projectDeliveryWorkflowManifest(
            version: 2,
            name: 'Project delivery workflow with recovery',
            states: [
                'queued',
                'running',
                'retry_scheduled',
                'completed',
                'failed',
            ],
            transitions: [
                [
                    'name' => 'start',
                    'from' => 'queued',
                    'to' => 'running',
                    'guard' => null,
                ],
                [
                    'name' => 'schedule_retry',
                    'from' => 'running',
                    'to' => 'retry_scheduled',
                    'guard' => 'retry_policy_allows',
                ],
                [
                    'name' => 'resume',
                    'from' => 'retry_scheduled',
                    'to' => 'running',
                    'guard' => 'retry_due',
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
        ),
    );

    expect($resolve->exact('project_delivery', 1)->is($versionOne))
        ->toBeTrue()
        ->and($resolve->exact('project_delivery', 2)->is($versionTwo))
        ->toBeTrue()
        ->and($resolve->latest('project_delivery')->is($versionTwo))
        ->toBeTrue()
        ->and($versionOne->definition)
        ->not->toBe($versionTwo->definition);

    $this->assertDatabaseCount('workflow_definitions', 2);
});

it('returns the existing row for an identical registration', function (): void {
    $manifest = projectDeliveryWorkflowManifest(version: 1);
    $register = app(RegisterWorkflowDefinition::class);

    $first = $register->handle($manifest);
    $second = $register->handle($manifest);

    expect($second->is($first))->toBeTrue();

    $this->assertDatabaseCount('workflow_definitions', 1);
});

it('rejects different content for an existing key and version', function (): void {
    $register = app(RegisterWorkflowDefinition::class);

    $register->handle(projectDeliveryWorkflowManifest(version: 1));

    expect(
        fn () => $register->handle(
            projectDeliveryWorkflowManifest(
                version: 1,
                name: 'Conflicting project delivery workflow',
            ),
        ),
    )->toThrow(
        ConflictException::class,
        'Workflow definition "project_delivery" version 1 already exists with different content.',
    );

    $this->assertDatabaseCount('workflow_definitions', 1);
});

it('rejects application-level updates and deletion', function (): void {
    $definition = app(RegisterWorkflowDefinition::class)->handle(
        projectDeliveryWorkflowManifest(version: 1),
    );

    $definition->name = 'Tampered workflow';

    expect(
        fn (): bool => $definition->save(),
    )->toThrow(
        LogicException::class,
        'Workflow definition versions are immutable.',
    );

    expect(
        fn (): ?bool => $definition->delete(),
    )->toThrow(
        LogicException::class,
        'Workflow definition versions cannot be deleted.',
    );

    $this->assertDatabaseHas('workflow_definitions', [
        'id' => $definition->id,
        'definition_key' => 'project_delivery',
        'version' => 1,
        'name' => 'Project delivery workflow',
    ]);
});

/**
 * Build a valid project-delivery definition fixture.
 *
 * @param  list<string>  $states
 * @param  list<array{
 *     name: string,
 *     from: string,
 *     to: string,
 *     guard?: string|null
 * }>  $transitions
 */
function projectDeliveryWorkflowManifest(
    int $version,
    string $name = 'Project delivery workflow',
    array $states = ['queued', 'running', 'completed', 'failed'],
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
        version: $version,
        schemaVersion: 1,
        name: $name,
        description: 'Controls one deterministic project-delivery execution.',
        initialState: 'queued',
        states: $states,
        terminalStates: ['completed', 'failed'],
        transitions: $transitions,
    );
}
