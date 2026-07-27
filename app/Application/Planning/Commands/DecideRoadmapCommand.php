<?php

declare(strict_types=1);

namespace App\Application\Planning\Commands;

use App\Application\Planning\RoadmapCommandFingerprint;
use App\Application\Shared\Idempotency\Contracts\IdempotentCommand;
use App\Domain\Approvals\ApprovalDecision;

final readonly class DecideRoadmapCommand implements IdempotentCommand
{
    public function __construct(
        public int $organizationId,
        public int $projectId,
        public int $roadmapId,
        public int $actorUserId,
        public ApprovalDecision $decision,
        public int $expectedContentVersion,
        public string $expectedFingerprint,
        public string $requestIdempotencyKey,
        public string $correlationId,
        public ?string $reason = null,
    ) {}

    public function idempotencyKey(): string
    {
        return $this->requestIdempotencyKey;
    }

    public function idempotencyScope(): string
    {
        return sprintf('organization:%d:project:%d:roadmap:%d:decide', $this->organizationId, $this->projectId, $this->roadmapId);
    }

    /** @return array<string, mixed> */
    public function idempotencyPayload(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'project_id' => $this->projectId,
            'roadmap_id' => $this->roadmapId,
            'actor_user_id' => $this->actorUserId,
            'decision' => $this->decision->value,
            'expected_content_version' => $this->expectedContentVersion,
            'expected_fingerprint' => $this->expectedFingerprint,
            'reason_fingerprint' => RoadmapCommandFingerprint::make($this->reason),
        ];
    }
}
