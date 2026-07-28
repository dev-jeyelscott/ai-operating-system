<?php

declare(strict_types=1);

use App\Application\Tickets\Data\TicketEligibilityContext;
use App\Application\Tickets\TicketEligibilityEvaluator;
use App\Domain\Projects\ProjectStatus;
use App\Domain\Tickets\TicketIneligibilityReason;
use App\Domain\Tickets\TicketStatus;
use InvalidArgumentException;

/**
 * Create a valid default eligibility context with optional gate overrides.
 *
 * @param  list<TicketStatus>  $hardDependencyStatuses
 */
function aios090EligibilityContext(
    TicketStatus $status = TicketStatus::Ready,
    bool $changesRequestedApproved = false,
    array $hardDependencyStatuses = [TicketStatus::Done],
    bool $hasUnresolvedBlocker = false,
    bool $approvalRequired = false,
    bool $approvalGranted = false,
    ProjectStatus $projectStatus = ProjectStatus::Active,
    bool $providerSupportsExecution = true,
    bool $budgetPermitsExecution = true,
    int $attemptCount = 0,
    int $retryLimit = 3,
): TicketEligibilityContext {
    return new TicketEligibilityContext(
        status: $status,
        changesRequestedApproved: $changesRequestedApproved,
        hardDependencyStatuses: $hardDependencyStatuses,
        hasUnresolvedBlocker: $hasUnresolvedBlocker,
        approvalRequired: $approvalRequired,
        approvalGranted: $approvalGranted,
        projectStatus: $projectStatus,
        providerSupportsExecution: $providerSupportsExecution,
        budgetPermitsExecution: $budgetPermitsExecution,
        attemptCount: $attemptCount,
        retryLimit: $retryLimit,
    );
}

test(
    'ready ticket is eligible when every execution gate passes',
    function (): void {
        $result = (new TicketEligibilityEvaluator)->evaluate(
            aios090EligibilityContext(),
        );

        expect($result->isEligible())
            ->toBeTrue()
            ->and($result->reasons)
            ->toBe([])
            ->and($result->reasonValues())
            ->toBe([]);
    },
);

test(
    'approved changes requested ticket is eligible',
    function (): void {
        $result = (new TicketEligibilityEvaluator)->evaluate(
            aios090EligibilityContext(
                status: TicketStatus::ChangesRequested,
                changesRequestedApproved: true,
                attemptCount: 1,
            ),
        );

        expect($result->isEligible())->toBeTrue();
    },
);

test(
    'changes requested ticket requires explicit approval',
    function (): void {
        $result = (new TicketEligibilityEvaluator)->evaluate(
            aios090EligibilityContext(
                status: TicketStatus::ChangesRequested,
                changesRequestedApproved: false,
            ),
        );

        expect($result->isEligible())
            ->toBeFalse()
            ->and($result->reasonValues())
            ->toBe([
                TicketIneligibilityReason::ChangesRequestedNotApproved->value,
            ]);
    },
);

test(
    'unsupported authoritative status is not eligible',
    function (TicketStatus $status): void {
        $result = (new TicketEligibilityEvaluator)->evaluate(
            aios090EligibilityContext(status: $status),
        );

        expect($result->isEligible())
            ->toBeFalse()
            ->and($result->reasonValues())
            ->toBe([
                TicketIneligibilityReason::StatusNotEligible->value,
            ]);
    },
)->with([
    'backlog' => [TicketStatus::Backlog],
    'in progress' => [TicketStatus::InProgress],
    'blocked' => [TicketStatus::Blocked],
    'for QA' => [TicketStatus::ForQa],
    'approved for merge' => [TicketStatus::ApprovedForMerge],
    'done' => [TicketStatus::Done],
    'cancelled' => [TicketStatus::Cancelled],
]);

test(
    'all failed execution gates are returned in deterministic order',
    function (): void {
        $result = (new TicketEligibilityEvaluator)->evaluate(
            aios090EligibilityContext(
                hardDependencyStatuses: [
                    TicketStatus::Done,
                    TicketStatus::Ready,
                ],
                hasUnresolvedBlocker: true,
                approvalRequired: true,
                approvalGranted: false,
                projectStatus: ProjectStatus::Paused,
                providerSupportsExecution: false,
                budgetPermitsExecution: false,
                attemptCount: 4,
                retryLimit: 3,
            ),
        );

        expect($result->isEligible())
            ->toBeFalse()
            ->and($result->reasonValues())
            ->toBe([
                TicketIneligibilityReason::DependencyIncomplete->value,
                TicketIneligibilityReason::BlockerUnresolved->value,
                TicketIneligibilityReason::ApprovalMissing->value,
                TicketIneligibilityReason::ProjectNotActive->value,
                TicketIneligibilityReason::ProviderUnavailable->value,
                TicketIneligibilityReason::BudgetUnavailable->value,
                TicketIneligibilityReason::RetryPolicyExhausted->value,
            ]);
    },
);

test(
    'retry limit permits the initial attempt and final allowed retry',
    function (): void {
        $initialAttempt = (new TicketEligibilityEvaluator)->evaluate(
            aios090EligibilityContext(
                attemptCount: 0,
                retryLimit: 0,
            ),
        );

        $finalRetry = (new TicketEligibilityEvaluator)->evaluate(
            aios090EligibilityContext(
                attemptCount: 3,
                retryLimit: 3,
            ),
        );

        $exhausted = (new TicketEligibilityEvaluator)->evaluate(
            aios090EligibilityContext(
                attemptCount: 4,
                retryLimit: 3,
            ),
        );

        expect($initialAttempt->isEligible())
            ->toBeTrue()
            ->and($finalRetry->isEligible())
            ->toBeTrue()
            ->and($exhausted->isEligible())
            ->toBeFalse()
            ->and($exhausted->reasonValues())
            ->toBe([
                TicketIneligibilityReason::RetryPolicyExhausted->value,
            ]);
    },
);

test(
    'eligibility context rejects invalid retry counters',
    function (): void {
        expect(
            fn (): TicketEligibilityContext => aios090EligibilityContext(attemptCount: -1),
        )->toThrow(
            InvalidArgumentException::class,
            'Execution attempt count cannot be negative.',
        );

        expect(
            fn (): TicketEligibilityContext => aios090EligibilityContext(retryLimit: -1),
        )->toThrow(
            InvalidArgumentException::class,
            'Execution retry limit cannot be negative.',
        );
    },
);
