<?php

declare(strict_types=1);

namespace App\Application\Tickets\Data;

use App\Domain\Projects\ProjectStatus;
use App\Domain\Tickets\TicketStatus;
use InvalidArgumentException;

/**
 * Immutable snapshot of the facts required to evaluate one ticket.
 *
 * The future atomic selector must construct this context from authoritative
 * records inside its selection transaction. The evaluator itself performs no
 * database queries and does not trust external provider claims directly.
 */
final readonly class TicketEligibilityContext
{
    /**
     * Store the validated hard dependency statuses as an ordered list.
     *
     * @var list<TicketStatus>
     */
    public array $hardDependencyStatuses;

    /**
     * Create a validated eligibility context.
     *
     * attemptCount is the number of execution attempts already consumed.
     * retryLimit is the number of retries allowed after the initial attempt.
     *
     * The dependency input intentionally enters as an untrusted general array.
     * After runtime validation, it is normalized into list<TicketStatus>.
     *
     * @param  array<array-key, mixed>  $hardDependencyStatuses
     */
    public function __construct(
        public TicketStatus $status,
        public bool $changesRequestedApproved,
        array $hardDependencyStatuses,
        public bool $hasUnresolvedBlocker,
        public bool $approvalRequired,
        public bool $approvalGranted,
        public ProjectStatus $projectStatus,
        public bool $providerSupportsExecution,
        public bool $budgetPermitsExecution,
        public int $attemptCount,
        public int $retryLimit,
    ) {
        if (! array_is_list($hardDependencyStatuses)) {
            throw new InvalidArgumentException(
                'Hard dependency statuses must be a list.',
            );
        }

        $validatedDependencyStatuses = [];

        foreach ($hardDependencyStatuses as $dependencyStatus) {
            if (! $dependencyStatus instanceof TicketStatus) {
                throw new InvalidArgumentException(
                    'Every hard dependency status must be a TicketStatus.',
                );
            }

            $validatedDependencyStatuses[] = $dependencyStatus;
        }

        $this->hardDependencyStatuses =
            $validatedDependencyStatuses;

        if ($this->attemptCount < 0) {
            throw new InvalidArgumentException(
                'Execution attempt count cannot be negative.',
            );
        }

        if ($this->retryLimit < 0) {
            throw new InvalidArgumentException(
                'Execution retry limit cannot be negative.',
            );
        }
    }
}
