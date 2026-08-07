<?php

declare(strict_types=1);

use App\Application\Workflows\Contracts\WorkflowTransitionGuardEvaluator;
use App\Application\Workflows\CreateWorkflowInstance;
use App\Application\Workflows\RegisterWorkflowDefinition;
use App\Application\Workflows\TransitionWorkflowInstance;
use App\Domain\Workflows\Exceptions\InvalidWorkflowTransition;
use App\Domain\Workflows\Exceptions\WorkflowTransitionGuardRejected;
use App\Domain\Workflows\WorkflowDefinitionManifest;
use App\Models\Project;
use App\Models\WorkflowInstance;

it('binds a new instance to one immutable definition version', function (): void {
    $project = Project::factory()->create();

    $versionOne = app(RegisterWorkflowDefinition::class)->handle(
        workflowInstanceManifest(version: 1),
    );

    $versionTwo = app(RegisterWorkflowDefinition::class)->handle(
        workflowInstanceManifest(version: 2),
    );

    $instance = app(CreateWorkflowInstance::class)->handle(
        project: $project,
        definitionKey: 'project_delivery',
    );

    expect($instance->workflow_definition_id)
        ->toBe($versionTwo->id)
        ->and($instance->current_state)
        ->toBe('queued')
        ->and($instance->transition_sequence)
        ->toBe(0)
        ->and($instance->completed_at)
        ->toBeNull();

    app(RegisterWorkflowDefinition::class)->handle(
        workflowInstanceManifest(version: 3),
    );

    $instance->refresh();

    expect($instance->workflow_definition_id)
        ->toBe($versionTwo->id)
        ->not->toBe($versionOne->id);

    $this->assertDatabaseCount('workflow_instances', 1);
});

it('commits an allowed unguarded transition atomically', function (): void {
    $project = Project::factory()->create();

    app(RegisterWorkflowDefinition::class)->handle(
        workflowInstanceManifest(version: 1),
    );

    $instance = app(CreateWorkflowInstance::class)->handle(
        project: $project,
        definitionKey: 'project_delivery',
        version: 1,
    );

    $transitioned = app(TransitionWorkflowInstance::class)->handle(
        instance: $instance,
        transitionName: 'start',
    );

    expect($transitioned->current_state)
        ->toBe('running')
        ->and($transitioned->transition_sequence)
        ->toBe(1)
        ->and($transitioned->completed_at)
        ->toBeNull();

    $this->assertDatabaseHas('workflow_transitions', [
        'workflow_instance_id' => $instance->id,
        'sequence' => 1,
        'name' => 'start',
        'from_state' => 'queued',
        'to_state' => 'running',
        'guard' => null,
    ]);
});

it('rejects undefined and wrong-source transitions without side effects', function (): void {
    $project = Project::factory()->create();

    app(RegisterWorkflowDefinition::class)->handle(
        workflowInstanceManifest(version: 1),
    );

    $instance = app(CreateWorkflowInstance::class)->handle(
        project: $project,
        definitionKey: 'project_delivery',
        version: 1,
    );

    expect(
        fn () => app(TransitionWorkflowInstance::class)->handle(
            instance: $instance,
            transitionName: 'complete',
        ),
    )->toThrow(
        InvalidWorkflowTransition::class,
        'Workflow transition "complete" cannot run from state "queued"; expected "running".',
    );

    expect(
        fn () => app(TransitionWorkflowInstance::class)->handle(
            instance: $instance,
            transitionName: 'unknown',
        ),
    )->toThrow(
        InvalidWorkflowTransition::class,
        'Workflow transition "unknown" is not defined.',
    );

    $instance->refresh();

    expect($instance->current_state)
        ->toBe('queued')
        ->and($instance->transition_sequence)
        ->toBe(0);

    $this->assertDatabaseCount('workflow_transitions', 0);
});

it('fails closed when a required guard is unavailable', function (): void {
    $project = Project::factory()->create();

    app(RegisterWorkflowDefinition::class)->handle(
        workflowInstanceManifest(version: 1),
    );

    $instance = app(CreateWorkflowInstance::class)->handle(
        project: $project,
        definitionKey: 'project_delivery',
        version: 1,
    );

    $instance = app(TransitionWorkflowInstance::class)->handle(
        instance: $instance,
        transitionName: 'start',
    );

    expect(
        fn () => app(TransitionWorkflowInstance::class)->handle(
            instance: $instance,
            transitionName: 'complete',
        ),
    )->toThrow(
        WorkflowTransitionGuardRejected::class,
        sprintf(
            'Workflow guard "required_outputs_exist" rejected transition "complete" for instance %d.',
            $instance->id,
        ),
    );

    $instance->refresh();

    expect($instance->current_state)
        ->toBe('running')
        ->and($instance->transition_sequence)
        ->toBe(1)
        ->and($instance->completed_at)
        ->toBeNull();

    $this->assertDatabaseCount('workflow_transitions', 1);
});

it('commits a guarded transition only after deterministic approval', function (): void {
    $this->app->instance(
        WorkflowTransitionGuardEvaluator::class,
        new class implements WorkflowTransitionGuardEvaluator
        {
            /**
             * Approve only the expected guard with explicit deterministic input.
             *
             * @param  array<string, mixed>  $context
             */
            public function passes(
                string $guard,
                WorkflowInstance $instance,
                array $context,
            ): bool {
                return $guard === 'required_outputs_exist'
                    && $instance->current_state === 'running'
                    && ($context['required_outputs_exist'] ?? false) === true;
            }
        },
    );

    $project = Project::factory()->create();

    app(RegisterWorkflowDefinition::class)->handle(
        workflowInstanceManifest(version: 1),
    );

    $instance = app(CreateWorkflowInstance::class)->handle(
        project: $project,
        definitionKey: 'project_delivery',
        version: 1,
    );

    $transitions = app(TransitionWorkflowInstance::class);

    $instance = $transitions->handle(
        instance: $instance,
        transitionName: 'start',
    );

    $instance = $transitions->handle(
        instance: $instance,
        transitionName: 'complete',
        guardContext: [
            'required_outputs_exist' => true,
        ],
    );

    expect($instance->current_state)
        ->toBe('completed')
        ->and($instance->transition_sequence)
        ->toBe(2)
        ->and($instance->completed_at)
        ->not->toBeNull();

    $this->assertDatabaseHas('workflow_transitions', [
        'workflow_instance_id' => $instance->id,
        'sequence' => 2,
        'name' => 'complete',
        'from_state' => 'running',
        'to_state' => 'completed',
        'guard' => 'required_outputs_exist',
    ]);

    expect(
        fn () => $transitions->handle(
            instance: $instance,
            transitionName: 'start',
        ),
    )->toThrow(
        InvalidWorkflowTransition::class,
        'Workflow state "completed" is terminal and cannot transition.',
    );
});

it('revalidates stale instances under a database row lock', function (): void {
    $project = Project::factory()->create();

    app(RegisterWorkflowDefinition::class)->handle(
        workflowInstanceManifest(version: 1),
    );

    $instance = app(CreateWorkflowInstance::class)->handle(
        project: $project,
        definitionKey: 'project_delivery',
        version: 1,
    );

    /** @var WorkflowInstance $firstWorker */
    $firstWorker = $instance->fresh();

    /** @var WorkflowInstance $secondWorker */
    $secondWorker = $instance->fresh();

    $transitions = app(TransitionWorkflowInstance::class);

    $transitions->handle(
        instance: $firstWorker,
        transitionName: 'start',
    );

    expect(
        fn () => $transitions->handle(
            instance: $secondWorker,
            transitionName: 'start',
        ),
    )->toThrow(
        InvalidWorkflowTransition::class,
        'Workflow transition "start" cannot run from state "running"; expected "queued".',
    );

    $instance->refresh();

    expect($instance->current_state)
        ->toBe('running')
        ->and($instance->transition_sequence)
        ->toBe(1);

    $this->assertDatabaseCount('workflow_transitions', 1);
});

/**
 * Build one deterministic project-delivery workflow definition.
 */
function workflowInstanceManifest(
    int $version,
): WorkflowDefinitionManifest {
    return new WorkflowDefinitionManifest(
        definitionKey: 'project_delivery',
        version: $version,
        schemaVersion: 1,
        name: sprintf(
            'Project delivery workflow version %d',
            $version,
        ),
        description: 'Controls deterministic project-delivery state.',
        initialState: 'queued',
        states: [
            'queued',
            'running',
            'completed',
            'failed',
        ],
        terminalStates: [
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
}
