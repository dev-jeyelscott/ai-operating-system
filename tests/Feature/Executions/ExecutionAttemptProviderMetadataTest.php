<?php

declare(strict_types=1);

use App\Application\Executions\Data\ExecutionAttemptContext;
use App\Application\Executions\Data\ProviderSelection;
use App\Domain\Executions\ExecutionCapability;
use App\Domain\Projects\Configuration\ReasoningLevel;
use App\Models\ExecutionAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('persists simulation attempt metadata', function (): void {
    $selection = new ProviderSelection(
        providerId: 'simulation',
        modelIdentifier: null,
        protocolVersion: 'simulation.v1',
        sandboxProfile: 'simulation.noop',
        effectiveCapability: ExecutionCapability::DevelopmentExecute,
        selectionSource: 'immutable_configuration_snapshot',
        simulation: true,
    );

    $context = ExecutionAttemptContext::fromProviderSelection(
        selection: $selection,
        requestedReasoningLevel: ReasoningLevel::Medium,
        effectiveReasoningLevel: ReasoningLevel::Medium,
        reasoningResolutionSource: 'immutable_configuration_snapshot',
        simulationScenario: 'happy_path',
        simulationSeed: '106',
    );

    $attempt = ExecutionAttempt::factory()->create(
        $context->toPersistenceAttributes(),
    );

    expect($attempt->execution_provider)->toBe('simulation')
        ->and($attempt->provider_protocol_version)
        ->toBe('simulation.v1')
        ->and($attempt->provider_sandbox_profile)
        ->toBe('simulation.noop')
        ->and($attempt->effective_capability)
        ->toBe('development.execute')
        ->and($attempt->simulation_mode)
        ->toBe('simulated')
        ->and($attempt->simulation_scenario)
        ->toBe('happy_path')
        ->and($attempt->simulation_seed)
        ->toBe('106');
});

it('persists real provider metadata without fake simulation fields', function (): void {
    $selection = new ProviderSelection(
        providerId: 'codex',
        modelIdentifier: 'codex-model',
        protocolVersion: 'codex-app-server.v1',
        sandboxProfile: 'workspace-write',
        effectiveCapability: ExecutionCapability::DevelopmentExecute,
        selectionSource: 'immutable_configuration_snapshot',
        simulation: false,
    );

    $context = ExecutionAttemptContext::fromProviderSelection(
        selection: $selection,
        requestedReasoningLevel: ReasoningLevel::Medium,
        effectiveReasoningLevel: ReasoningLevel::Medium,
        reasoningResolutionSource: 'immutable_configuration_snapshot',
        simulationScenario: 'happy_path',
        simulationSeed: '106',
    );

    $attempt = ExecutionAttempt::factory()->create(
        $context->toPersistenceAttributes(),
    );

    expect($attempt->execution_provider)->toBe('codex')
        ->and($attempt->model_identifier)
        ->toBe('codex-model')
        ->and($attempt->effective_capability)
        ->toBe('development.execute')
        ->and($attempt->simulation_mode)->toBeNull()
        ->and($attempt->simulation_scenario)->toBeNull()
        ->and($attempt->simulation_seed)->toBeNull();
});
