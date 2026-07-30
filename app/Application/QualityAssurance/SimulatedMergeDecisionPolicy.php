<?php

namespace App\Application\QualityAssurance;

use App\Domain\QualityAssurance\MergeDecisionAction;
use App\Domain\QualityAssurance\QaDecision;
use App\Models\QaAssessment;

/**
 * Defines deterministic action rules shared by the command and read model.
 */
final readonly class SimulatedMergeDecisionPolicy
{
    /**
     * Determine whether the assessment permits simulated approval.
     */
    public function canApprove(QaAssessment $assessment): bool
    {
        return in_array(
            $assessment->decision,
            [
                QaDecision::MergeReady,
                QaDecision::MergeReadyWithRisks,
            ],
            true,
        ) && $this->blockingFindingCodes(
            $assessment->unresolved_findings,
        ) === [];
    }

    /**
     * Return the actions valid for this nonterminal assessment.
     *
     * @return list<MergeDecisionAction>
     */
    public function allowedActions(QaAssessment $assessment): array
    {
        return array_values(array_filter(
            MergeDecisionAction::cases(),
            fn (MergeDecisionAction $action): bool => $action
                !== MergeDecisionAction::Approve
                || $this->canApprove($assessment),
        ));
    }

    /**
     * Determine whether an action requires an explanatory reason.
     */
    public function reasonRequiredFor(MergeDecisionAction $action): bool
    {
        return $action->requiresReason();
    }

    /**
     * Return stable codes for explicitly blocking persisted findings.
     *
     * @return list<string>
     */
    public function blockingFindingCodes(mixed $findings): array
    {
        if (! is_array($findings)) {
            return [];
        }

        $codes = [];

        foreach ($findings as $finding) {
            if (
                ! is_array($finding)
                || ($finding['blocking'] ?? false) !== true
            ) {
                continue;
            }

            $code = $finding['code'] ?? null;

            if (is_string($code) && $code !== '') {
                $codes[] = $code;
            }
        }

        sort($codes, SORT_STRING);

        return array_values(array_unique($codes));
    }
}
