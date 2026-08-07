<?php

declare(strict_types=1);

namespace App\Application\QualityAssurance\Contracts;

use App\Application\QualityAssurance\Data\QaAssessmentResult;
use App\Application\QualityAssurance\Data\QualityAssuranceExecutionRequest;

/**
 * Defines the replaceable provider contract for Layer 3 execution.
 */
interface QualityAssuranceExecutionProvider
{
    /**
     * Return the stable provider identifier.
     */
    public function id(): string;

    /**
     * Determine whether the provider supports the requested capability.
     */
    public function supports(string $capability): bool;

    /**
     * Execute one QA assessment request.
     */
    public function execute(
        QualityAssuranceExecutionRequest $request,
    ): QaAssessmentResult;
}
