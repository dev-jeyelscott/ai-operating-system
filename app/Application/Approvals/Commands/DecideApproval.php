<?php

declare(strict_types=1);

namespace App\Application\Approvals\Commands;

use App\Application\Shared\Commands\Command;
use App\Domain\Approvals\ApprovalDecision;

/**
 * Applies one authorized and idempotent approval decision.
 */
final readonly class DecideApproval implements Command
{
    /**
     * Store the immutable decision parameters.
     */
    public function __construct(
        public string $approvalId,
        public int $actorUserId,
        public ApprovalDecision $decision,
        public string $idempotencyKey,
        public string $correlationId,
        public ?string $reason = null,
        public ?string $causationId = null,
    ) {}
}
