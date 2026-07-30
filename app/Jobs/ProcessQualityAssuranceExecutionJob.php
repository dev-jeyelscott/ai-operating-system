<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\QualityAssurance\ProcessQualityAssuranceExecution;
use App\Domain\Executions\ExecutionStatus;
use App\Models\QaAssessment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Runs one Layer 3 execution through the domain-owned resilience lifecycle.
 */
final class ProcessQualityAssuranceExecutionJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use Queueable;

    /**
     * Provider timeout is owned by the execution configuration.
     */
    public int $timeout = 900;

    /**
     * Laravel must not introduce a competing retry loop.
     *
     * ExecutionResilienceManager owns retries and retry timing.
     */
    public int $tries = 1;

    public function __construct(
        public string $assessmentId,
    ) {}

    /**
     * Deduplicate queue delivery for the same QA assessment.
     */
    public function uniqueId(): string
    {
        return 'quality-assurance-assessment:'
            .$this->assessmentId;
    }

    /**
     * Process the assessment only while its review execution is queued.
     */
    public function handle(
        ProcessQualityAssuranceExecution $qualityAssurance,
    ): void {
        $assessment = QaAssessment::query()
            ->with('reviewExecution')
            ->findOrFail($this->assessmentId);

        if (
            $assessment->reviewExecution->status
                !== ExecutionStatus::Queued
        ) {
            return;
        }

        $qualityAssurance->handle($assessment);
    }
}
