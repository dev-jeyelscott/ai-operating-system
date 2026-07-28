<?php

declare(strict_types=1);

namespace App\Application\Tickets\Data;

use InvalidArgumentException;

/**
 * Immutable request for one atomic ticket-selection attempt.
 *
 * Provider and budget booleans must come from deterministic application policy,
 * never directly from an execution provider response.
 */
final readonly class TicketSelectionRequest
{
    /**
     * Validate the project boundary, execution owner, and lease duration.
     */
    public function __construct(
        public int $organizationId,
        public int $projectId,
        public string $executionId,
        public string $owner,
        public bool $providerSupportsExecution,
        public bool $budgetPermitsExecution,
        public int $leaseDurationSeconds = 300,
    ) {
        if ($this->organizationId < 1) {
            throw new InvalidArgumentException(
                'Organization identifier must be positive.',
            );
        }

        if ($this->projectId < 1) {
            throw new InvalidArgumentException(
                'Project identifier must be positive.',
            );
        }

        if (
            $this->executionId === ''
            || trim($this->executionId) !== $this->executionId
        ) {
            throw new InvalidArgumentException(
                'Execution identifier must be a non-empty trimmed string.',
            );
        }

        if (
            $this->owner === ''
            || trim($this->owner) !== $this->owner
            || mb_strlen($this->owner) > 100
        ) {
            throw new InvalidArgumentException(
                'Lease owner must be a non-empty trimmed string of at most 100 characters.',
            );
        }

        if (
            $this->leaseDurationSeconds < 30
            || $this->leaseDurationSeconds > 3600
        ) {
            throw new InvalidArgumentException(
                'Lease duration must be between 30 and 3600 seconds.',
            );
        }
    }
}
