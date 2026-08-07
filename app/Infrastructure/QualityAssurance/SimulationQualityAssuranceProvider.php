<?php

declare(strict_types=1);

namespace App\Infrastructure\QualityAssurance;

use App\Application\QualityAssurance\Contracts\QualityAssuranceExecutionProvider;
use App\Application\QualityAssurance\Data\QaAssessmentResult;
use App\Application\QualityAssurance\Data\QualityAssuranceExecutionRequest;

/**
 * Executes deterministic Layer 3 scenarios through the simulation catalog.
 */
final readonly class SimulationQualityAssuranceProvider implements QualityAssuranceExecutionProvider
{
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
     * Support only the Layer 3 simulation capability.
     */
    public function supports(string $capability): bool
    {
        return $capability === 'quality_assurance.simulation';
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
