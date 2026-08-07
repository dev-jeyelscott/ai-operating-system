<?php

declare(strict_types=1);

namespace App\Application\QualityAssurance\Contracts;

use App\Application\Executions\Contracts\DescribesExecutionProvider;
use App\Application\QualityAssurance\Data\QaAssessmentResult;
use App\Application\QualityAssurance\Data\QualityAssuranceExecutionRequest;

/**
 * Defines the provider-neutral Layer 3 review boundary.
 */
interface QualityAssuranceExecutionProvider extends DescribesExecutionProvider
{
    /**
     * Execute one validated QA assessment request.
     */
    public function execute(
        QualityAssuranceExecutionRequest $request,
    ): QaAssessmentResult;
}
