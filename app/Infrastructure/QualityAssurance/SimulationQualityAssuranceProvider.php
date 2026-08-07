<?php

declare(strict_types=1);

namespace App\Infrastructure\QualityAssurance;

use App\Application\Executions\Data\ExecutionProviderMetadata;
use App\Application\QualityAssurance\Contracts\QualityAssuranceExecutionProvider;
use App\Application\QualityAssurance\Data\QaAssessmentResult;
use App\Application\QualityAssurance\Data\QualityAssuranceExecutionRequest;
use App\Domain\Executions\ExecutionCapability;

/**
 * Executes deterministic Layer 3 scenarios through the simulation catalog.
 */
final readonly class SimulationQualityAssuranceProvider implements QualityAssuranceExecutionProvider
{
    /**
     * Inject the deterministic scenario catalog.
     */
    public function __construct(
        private QaScenarioCatalog $scenarios,
    ) {}

    /**
     * Return the stable simulation provider identifier.
     */
    public function id(): string
    {
        return 'simulation';
    }

    /**
     * Support the canonical Layer 3 review capability.
     */
    public function supports(string $capability): bool
    {
        return $capability
            === ExecutionCapability::QualityAssuranceReview->value;
    }

    /**
     * Return immutable metadata for Layer 3 simulation.
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
     * Resolve the requested deterministic QA scenario.
     */
    public function execute(
        QualityAssuranceExecutionRequest $request,
    ): QaAssessmentResult {
        return $this->scenarios->resolve($request);
    }
}
