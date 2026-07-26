<?php

declare(strict_types=1);

use App\Domain\Executions\ExecutionAttemptStatus;
use App\Domain\Executions\ExecutionStatus;
use App\Domain\Projects\Configuration\ReasoningLevel;
use App\Models\Execution;
use App\Models\ExecutionAttempt;
use App\Models\Project;
use App\Models\WorkflowInstance;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

it('stores a tenant-scoped execution summary with a stable ULID', function (): void {
    $project = Project::factory()->create();

    $workflowInstance = WorkflowInstance::factory()->create([
        'project_id' => $project->id,
    ]);

    $execution = Execution::factory()->create([
        'project_id' => $project->id,
        'workflow_instance_id' => $workflowInstance->id,
        'capability' => 'planning.roadmap',
        'logical_role' => 'project_manager',
        'requested_reasoning_level' => ReasoningLevel::High,
        'idempotency_key' => 'start-project:context-001',
    ]);

    expect(Str::isUlid($execution->id))
        ->toBeTrue()
        ->and($execution->status)
        ->toBe(ExecutionStatus::Queued)
        ->and($execution->requested_reasoning_level)
        ->toBe(ReasoningLevel::High)
        ->and($execution->attempt_count)
        ->toBe(0)
        ->and($execution->project->is($project))
        ->toBeTrue()
        ->and($execution->workflowInstance?->is($workflowInstance))
        ->toBeTrue();
});

it('preserves provider reasoning state timing cost and error data per attempt', function (): void {
    $execution = Execution::factory()->create([
        'requested_reasoning_level' => ReasoningLevel::High,
    ]);

    $startedAt = now()->subSeconds(3);
    $finishedAt = now();

    $attempt = ExecutionAttempt::factory()->create([
        'execution_id' => $execution->id,
        'attempt_number' => 1,
        'status' => ExecutionAttemptStatus::Failed,
        'execution_provider' => 'simulation',
        'model_identifier' => 'simulation-v1',
        'requested_reasoning_level' => ReasoningLevel::High,
        'effective_reasoning_level' => ReasoningLevel::High,
        'reasoning_resolution_source' => 'ticket_override',
        'reasoning_escalation_reason' => 'Final planning readiness assessment.',
        'simulation_mode' => 'failure_path',
        'simulation_seed' => 'aios-052',
        'reported_state' => 'failed',
        'observed_state' => 'provider_error_recorded',
        'actual_state' => 'unverified',
        'confidence' => '0.8750',
        'estimated_cost' => '0.12500000',
        'actual_cost' => '0.10000000',
        'cost_currency' => 'USD',
        'error_code' => 'provider.timeout',
        'error_message' => 'The simulated provider timed out.',
        'started_at' => $startedAt,
        'finished_at' => $finishedAt,
    ]);

    /*
 * Reload the attempt so timestamp assertions verify the actual PostgreSQL
 * round trip rather than only the in-memory attributes assigned by Eloquent.
 */
    $attempt->refresh();

    expect($attempt->execution->is($execution))
        ->toBeTrue()
        ->and($attempt->status)
        ->toBe(ExecutionAttemptStatus::Failed)
        ->and($attempt->requested_reasoning_level)
        ->toBe(ReasoningLevel::High)
        ->and($attempt->effective_reasoning_level)
        ->toBe(ReasoningLevel::High)
        ->and($attempt->confidence)
        ->toBe('0.8750')
        ->and($attempt->estimated_cost)
        ->toBe('0.12500000')
        ->and($attempt->actual_cost)
        ->toBe('0.10000000')
        ->and($attempt->started_at?->equalTo($startedAt))
        ->toBeTrue()
        ->and($attempt->finished_at?->equalTo($finishedAt))
        ->toBeTrue()
        ->and($attempt->error_code)
        ->toBe('provider.timeout');
});

it('orders attempts by their deterministic attempt number', function (): void {
    $execution = Execution::factory()->create();

    ExecutionAttempt::factory()->create([
        'execution_id' => $execution->id,
        'attempt_number' => 2,
    ]);

    ExecutionAttempt::factory()->create([
        'execution_id' => $execution->id,
        'attempt_number' => 1,
    ]);

    expect(
        $execution
            ->attempts()
            ->pluck('attempt_number')
            ->all(),
    )->toBe([1, 2]);
});

it('rejects duplicate idempotency keys within the same project', function (): void {
    $project = Project::factory()->create();

    Execution::factory()->create([
        'project_id' => $project->id,
        'idempotency_key' => 'planning:context-001',
    ]);

    expect(
        fn () => Execution::factory()->create([
            'project_id' => $project->id,
            'idempotency_key' => 'planning:context-001',
        ]),
    )->toThrow(QueryException::class);
});

it('allows the same idempotency key in different projects', function (): void {
    $first = Execution::factory()->create([
        'idempotency_key' => 'planning:context-001',
    ]);

    $second = Execution::factory()->create([
        'idempotency_key' => 'planning:context-001',
    ]);

    expect($first->project_id)
        ->not->toBe($second->project_id)
        ->and($first->idempotency_key)
        ->toBe($second->idempotency_key);
});

it('rejects duplicate attempt numbers for one execution', function (): void {
    $execution = Execution::factory()->create();

    ExecutionAttempt::factory()->create([
        'execution_id' => $execution->id,
        'attempt_number' => 1,
    ]);

    expect(
        fn () => ExecutionAttempt::factory()->create([
            'execution_id' => $execution->id,
            'attempt_number' => 1,
        ]),
    )->toThrow(QueryException::class);
});

it('rejects a workflow instance belonging to another project', function (): void {
    $executionProject = Project::factory()->create();
    $otherWorkflow = WorkflowInstance::factory()->create();

    expect(
        fn () => Execution::factory()->create([
            'project_id' => $executionProject->id,
            'workflow_instance_id' => $otherWorkflow->id,
        ]),
    )->toThrow(QueryException::class);
});

it('protects immutable execution identity and history', function (): void {
    $execution = Execution::factory()->create();
    $anotherProject = Project::factory()->create();

    $execution->forceFill([
        'project_id' => $anotherProject->id,
    ]);

    expect(fn () => $execution->save())
        ->toThrow(
            LogicException::class,
            'Execution identity, request context, and resilience policy are immutable.',
        );

    $execution->refresh();

    expect(fn () => $execution->delete())
        ->toThrow(
            LogicException::class,
            'Executions cannot be deleted.',
        );
});

it('protects immutable attempt provider context and history', function (): void {
    $attempt = ExecutionAttempt::factory()->create();

    $attempt->forceFill([
        'execution_provider' => 'openai',
    ]);

    expect(fn () => $attempt->save())
        ->toThrow(
            LogicException::class,
            'Execution attempt identity and provider context are immutable.',
        );

    $attempt->refresh();

    expect(fn () => $attempt->delete())
        ->toThrow(
            LogicException::class,
            'Execution attempts cannot be deleted.',
        );
});

it('rejects invalid confidence at the database boundary', function (): void {
    expect(
        fn () => ExecutionAttempt::factory()->create([
            'confidence' => '1.1000',
        ]),
    )->toThrow(QueryException::class);
});
