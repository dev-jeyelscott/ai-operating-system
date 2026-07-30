<?php

declare(strict_types=1);

namespace App\Application\QualityAssurance\Data;

use App\Domain\Executions\ExecutionAttemptStatus;
use App\Domain\Executions\ExecutionStatus;
use App\Domain\Tickets\TicketStatus;
use InvalidArgumentException;

/**
 * Contains the authoritative facts required to evaluate one For QA ticket.
 *
 * AIOS-106 must construct this context from tenant-scoped records belonging to
 * the same ticket, completed development execution, attempt, lease, and project
 * context snapshot. This object itself performs no database queries.
 */
final readonly class ForQaTicketEligibilityContext
{
    /**
     * Store validated artifacts as an ordered list.
     *
     * @var list<ForQaArtifactFact>
     */
    public array $artifacts;

    /**
     * Create a validated QA eligibility context.
     *
     * Null execution or attempt values represent missing authoritative records
     * and allow the evaluator to return stable fail-closed reasons.
     *
     * @param  array<array-key, mixed>  $artifacts
     */
    public function __construct(
        public TicketStatus $ticketStatus,
        public int $ticketProjectId,
        public int $roadmapContextSnapshotId,
        public ?string $implementationExecutionId,
        public ?int $implementationProjectId,
        public ?int $implementationContextSnapshotId,
        public ?string $implementationCapability,
        public ?ExecutionStatus $implementationExecutionStatus,
        public ?int $implementationAttemptId,
        public ?ExecutionAttemptStatus $implementationAttemptStatus,
        public bool $completionLeaseRecorded,
        array $artifacts,
    ) {
        if ($this->ticketProjectId < 1) {
            throw new InvalidArgumentException(
                'Ticket project ID must be positive.',
            );
        }

        if ($this->roadmapContextSnapshotId < 1) {
            throw new InvalidArgumentException(
                'Roadmap context-snapshot ID must be positive.',
            );
        }

        $this->validateExecutionFacts();
        $this->validateAttemptFacts();

        if (! array_is_list($artifacts)) {
            throw new InvalidArgumentException(
                'QA eligibility artifacts must be a list.',
            );
        }

        $validatedArtifacts = [];

        foreach ($artifacts as $artifact) {
            if (! $artifact instanceof ForQaArtifactFact) {
                throw new InvalidArgumentException(
                    'Every QA eligibility artifact must be a ForQaArtifactFact.',
                );
            }

            $validatedArtifacts[] = $artifact;
        }

        $this->artifacts = $validatedArtifacts;
    }

    /**
     * Validate the nullable implementation-execution fact group.
     */
    private function validateExecutionFacts(): void
    {
        if ($this->implementationExecutionId === null) {
            if (
                $this->implementationProjectId !== null
                || $this->implementationCapability !== null
                || $this->implementationExecutionStatus !== null
                || $this->implementationContextSnapshotId !== null
            ) {
                throw new InvalidArgumentException(
                    'Missing implementation execution cannot include execution facts.',
                );
            }

            return;
        }

        if (
            trim($this->implementationExecutionId) === ''
            || $this->implementationProjectId === null
            || $this->implementationProjectId < 1
            || $this->implementationCapability === null
            || trim($this->implementationCapability) === ''
            || $this->implementationExecutionStatus === null
        ) {
            throw new InvalidArgumentException(
                'Implementation execution facts are incomplete.',
            );
        }

        if (
            $this->implementationContextSnapshotId !== null
            && $this->implementationContextSnapshotId < 1
        ) {
            throw new InvalidArgumentException(
                'Implementation context-snapshot ID must be positive.',
            );
        }
    }

    /**
     * Validate the nullable implementation-attempt fact group.
     */
    private function validateAttemptFacts(): void
    {
        if ($this->implementationAttemptId === null) {
            if ($this->implementationAttemptStatus !== null) {
                throw new InvalidArgumentException(
                    'Missing implementation attempt cannot include attempt status.',
                );
            }

            return;
        }

        if (
            $this->implementationAttemptId < 1
            || $this->implementationAttemptStatus === null
        ) {
            throw new InvalidArgumentException(
                'Implementation attempt facts are incomplete.',
            );
        }
    }
}
