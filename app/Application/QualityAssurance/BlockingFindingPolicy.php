<?php

declare(strict_types=1);

namespace App\Application\QualityAssurance;

use App\Application\QualityAssurance\Data\QaAssessmentResult;
use App\Application\QualityAssurance\Data\QaFinding;
use App\Domain\QualityAssurance\QaDecision;
use InvalidArgumentException;

/**
 * Prevents approval recommendations while unresolved blocking QA findings exist.
 */
final class BlockingFindingPolicy
{
    /**
     * Decisions that recommend allowing the simulated merge workflow to advance.
     *
     * @var list<QaDecision>
     */
    private const array APPROVAL_RECOMMENDATIONS = [
        QaDecision::MergeReady,
        QaDecision::MergeReadyWithRisks,
    ];

    /**
     * Reject a contradictory provider result that recommends approval with blockers.
     */
    public function assertRecommendationAllowed(
        QaAssessmentResult $assessment,
    ): void {
        if (! in_array(
            $assessment->decision,
            self::APPROVAL_RECOMMENDATIONS,
            true,
        )) {
            return;
        }

        $blockingCodes = $this->blockingCodes(
            $assessment->unresolvedFindings,
        );

        if ($blockingCodes === []) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            'QA decision [%s] cannot recommend approval while unresolved blocking findings exist: %s.',
            $assessment->decision->value,
            implode(', ', $blockingCodes),
        ));
    }

    /**
     * Return stable finding codes for every unresolved blocker.
     *
     * @param  list<QaFinding>  $findings
     * @return list<string>
     */
    private function blockingCodes(array $findings): array
    {
        $codes = [];

        foreach ($findings as $finding) {
            if ($finding->blocking) {
                $codes[] = $finding->code;
            }
        }

        sort($codes, SORT_STRING);

        return $codes;
    }
}
