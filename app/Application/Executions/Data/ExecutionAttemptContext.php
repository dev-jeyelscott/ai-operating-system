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
    ) {}

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
            'requested_reasoning_level' => $this->requestedReasoningLevel,
            'effective_reasoning_level' => $this->effectiveReasoningLevel,
            'reasoning_resolution_source' => $this->reasoningResolutionSource,
            'reasoning_escalation_reason' => $this->reasoningEscalationReason,
            'simulation_mode' => $this->simulationMode,
            'simulation_seed' => $this->simulationSeed,
        ];
    }
}
