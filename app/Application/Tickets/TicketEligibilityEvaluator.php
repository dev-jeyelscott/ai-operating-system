<?php

declare(strict_types=1);

namespace App\Application\Tickets;

use App\Application\Tickets\Data\TicketEligibilityContext;
use App\Application\Tickets\Data\TicketEligibilityResult;
use App\Domain\Projects\ProjectStatus;
use App\Domain\Tickets\TicketIneligibilityReason;
use App\Domain\Tickets\TicketStatus;

/**
 * Applies deterministic execution-eligibility policy to one ticket snapshot.
 *
 * This service deliberately performs no database queries or state mutations.
 * Atomic selection and lease acquisition remain the responsibility of
 * AIOS-092.
 */
final class TicketEligibilityEvaluator
{
    /**
     * Evaluate every eligibility gate and return all failed reasons.
     */
    public function evaluate(
        TicketEligibilityContext $context,
    ): TicketEligibilityResult {
        $reasons = [];

        $this->evaluateStatus(
            context: $context,
            reasons: $reasons,
        );

        if ($this->hasIncompleteHardDependency(
            $context->hardDependencyStatuses,
        )) {
            $reasons[] =
                TicketIneligibilityReason::DependencyIncomplete;
        }

        if ($context->hasUnresolvedBlocker) {
            $reasons[] =
                TicketIneligibilityReason::BlockerUnresolved;
        }

        if (
            $context->approvalRequired
            && ! $context->approvalGranted
        ) {
            $reasons[] =
                TicketIneligibilityReason::ApprovalMissing;
        }

        if ($context->projectStatus !== ProjectStatus::Active) {
            $reasons[] =
                TicketIneligibilityReason::ProjectNotActive;
        }

        if (! $context->providerSupportsExecution) {
            $reasons[] =
                TicketIneligibilityReason::ProviderUnavailable;
        }

        if (! $context->budgetPermitsExecution) {
            $reasons[] =
                TicketIneligibilityReason::BudgetUnavailable;
        }

        if ($this->hasExhaustedRetryPolicy($context)) {
            $reasons[] =
                TicketIneligibilityReason::RetryPolicyExhausted;
        }

        return new TicketEligibilityResult($reasons);
    }

    /**
     * Evaluate Ready and approved Changes Requested status rules.
     *
     * @param  list<TicketIneligibilityReason>  $reasons
     */
    private function evaluateStatus(
        TicketEligibilityContext $context,
        array &$reasons,
    ): void {
        if ($context->status === TicketStatus::ChangesRequested) {
            if (! $context->changesRequestedApproved) {
                $reasons[] =
                    TicketIneligibilityReason::ChangesRequestedNotApproved;
            }

            return;
        }

        if ($context->status !== TicketStatus::Ready) {
            $reasons[] =
                TicketIneligibilityReason::StatusNotEligible;
        }
    }

    /**
     * Determine whether at least one hard dependency is not Done.
     *
     * @param  list<TicketStatus>  $dependencyStatuses
     */
    private function hasIncompleteHardDependency(
        array $dependencyStatuses,
    ): bool {
        foreach ($dependencyStatuses as $dependencyStatus) {
            if ($dependencyStatus !== TicketStatus::Done) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether no initial or retry attempt remains.
     *
     * A retry limit of zero still permits the initial attempt. For example:
     *
     * attemptCount 0, retryLimit 0 => initial attempt remains
     * attemptCount 3, retryLimit 3 => fourth and final attempt remains
     * attemptCount 4, retryLimit 3 => all attempts are exhausted
     */
    private function hasExhaustedRetryPolicy(
        TicketEligibilityContext $context,
    ): bool {
        return $context->attemptCount > $context->retryLimit;
    }
}
