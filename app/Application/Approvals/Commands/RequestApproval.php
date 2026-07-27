<?php

declare(strict_types=1);

namespace App\Application\Approvals\Commands;

use App\Application\Shared\Commands\Command;
use App\Domain\Approvals\ApprovalType;
use Carbon\CarbonImmutable;

/**
 * Requests one idempotent project-scoped human approval.
 */
final readonly class RequestApproval implements Command
{
    /**
     * Store the immutable request parameters.
     *
     * The payload accepts native PHP array keys at this boundary. The approval
     * handler validates that every key is a string before persistence so the
     * resulting JSON payload always has a stable object shape.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public function __construct(
        public int $projectId,
        public ApprovalType $type,
        public string $idempotencyKey,
        public string $correlationId,
        public ?int $workflowInstanceId = null,
        public ?string $executionId = null,
        public ?int $requestedByUserId = null,
        public ?CarbonImmutable $expiresAt = null,
        public array $payload = [],
        public ?string $causationId = null,
    ) {}
}
