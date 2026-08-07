<?php

declare(strict_types=1);

namespace App\Application\Executions\Data;

use App\Domain\Projects\Configuration\ReasoningLevel;

/**
 * Carries the immutable provider and reasoning snapshot for one attempt.
 */
final readonly class ExecutionAttemptContext
{
    /**
     * Create an immutable attempt context.
     */
    public function __construct(
        public string $executionProvider,
        public ?string $modelIdentifier,
        public ReasoningLevel $requestedReasoningLevel,
        public ReasoningLevel $effectiveReasoningLevel,
        public string $reasoningResolutionSource,
        public ?string $reasoningEscalationReason = null,
        public ?string $simulationMode = null,
        public ?string $simulationSeed = null,
        public ?string $providerProtocolVersion = null,
        public ?string $providerSandboxProfile = null,
        public ?string $effectiveCapability = null,
        public ?string $providerSelectionSource = null,
        public ?string $simulationScenario = null,
    ) {}

    /**
     * Build an attempt context from one resolved provider selection.
     *
     * Simulation-only fields are left null for every non-simulation provider.
     */
    public static function fromProviderSelection(
        ProviderSelection $selection,
        ReasoningLevel $requestedReasoningLevel,
        ReasoningLevel $effectiveReasoningLevel,
        string $reasoningResolutionSource,
        ?string $reasoningEscalationReason = null,
        ?string $simulationScenario = null,
        ?string $simulationSeed = null,
    ): self {
        return new self(
            executionProvider: $selection->providerId,
            modelIdentifier: $selection->modelIdentifier,
            requestedReasoningLevel: $requestedReasoningLevel,
            effectiveReasoningLevel: $effectiveReasoningLevel,
            reasoningResolutionSource: $reasoningResolutionSource,
            reasoningEscalationReason: $reasoningEscalationReason,
            simulationMode: $selection->simulation
                ? 'simulated'
                : null,
            simulationSeed: $selection->simulation
                ? $simulationSeed
                : null,
            providerProtocolVersion: $selection->protocolVersion,
            providerSandboxProfile: $selection->sandboxProfile,
            effectiveCapability: $selection->effectiveCapability->value,
            providerSelectionSource: $selection->selectionSource,
            simulationScenario: $selection->simulation
                ? $simulationScenario
                : null,
        );
    }

    /**
     * Convert the context into execution-attempt persistence attributes.
     *
     * @return array<string, mixed>
     */
    public function toPersistenceAttributes(): array
    {
        return [
            'execution_provider' => $this->executionProvider,
            'model_identifier' => $this->modelIdentifier,
            'provider_protocol_version' => $this
                ->providerProtocolVersion,
            'provider_sandbox_profile' => $this
                ->providerSandboxProfile,
            'effective_capability' => $this->effectiveCapability,
            'provider_selection_source' => $this
                ->providerSelectionSource,
            'requested_reasoning_level' => $this
                ->requestedReasoningLevel,
            'effective_reasoning_level' => $this
                ->effectiveReasoningLevel,
            'reasoning_resolution_source' => $this
                ->reasoningResolutionSource,
            'reasoning_escalation_reason' => $this
                ->reasoningEscalationReason,
            'simulation_mode' => $this->simulationMode,
            'simulation_scenario' => $this->simulationScenario,
            'simulation_seed' => $this->simulationSeed,
        ];
    }
}
