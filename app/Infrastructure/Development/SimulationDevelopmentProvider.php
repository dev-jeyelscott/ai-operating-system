<?php

declare(strict_types=1);

namespace App\Infrastructure\Development;

use App\Application\Development\Contracts\DevelopmentExecutionProvider;
use App\Application\Development\Data\DevelopmentExecutionRequest;
use App\Application\Development\Data\DevelopmentExecutionResult;
use App\Application\Development\SyntheticDevelopmentArtifactGenerator;
use App\Application\Executions\Data\ExecutionProviderMetadata;
use App\Domain\Executions\ExecutionCapability;

/**
 * Executes deterministic Layer 2 simulations without repository access.
 */
final readonly class SimulationDevelopmentProvider implements DevelopmentExecutionProvider
{
    /**
     * Inject the deterministic artifact generator.
     */
    public function __construct(
        private SyntheticDevelopmentArtifactGenerator $generator,
    ) {}

    /**
     * Return the stable simulation provider identifier.
     */
    public function id(): string
    {
        return 'simulation';
    }

    /**
     * Support the canonical Layer 2 development capability.
     */
    public function supports(string $capability): bool
    {
        return $capability
            === ExecutionCapability::DevelopmentExecute->value;
    }

    /**
     * Return immutable metadata for Layer 2 simulation.
     */
    public function metadata(): ExecutionProviderMetadata
    {
        return new ExecutionProviderMetadata(
            modelIdentifier: null,
            protocolVersion: 'simulation.v1',
            sandboxProfile: 'simulation.noop',
            simulation: true,
        );
    }

    /**
     * Produce one deterministic synthetic development result.
     */
    public function execute(
        DevelopmentExecutionRequest $request,
    ): DevelopmentExecutionResult {
        return $this->generator->generate($request);
    }
}
