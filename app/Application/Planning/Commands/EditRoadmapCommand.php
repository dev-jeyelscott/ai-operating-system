<?php

declare(strict_types=1);

namespace App\Application\Planning\Commands;

use App\Application\Planning\RoadmapCommandFingerprint;
use App\Application\Shared\Idempotency\Contracts\IdempotentCommand;

final readonly class EditRoadmapCommand implements IdempotentCommand
{
    /** @param array<string, mixed> $patch */
    public function __construct(
        public int $organizationId,
        public int $projectId,
        public int $roadmapId,
        public int $actorUserId,
        public int $expectedContentVersion,
        public string $expectedFingerprint,
        public array $patch,
        public string $requestIdempotencyKey,
        public string $correlationId,
    ) {}

    public function idempotencyKey(): string
    {
        return $this->requestIdempotencyKey;
    }

    public function idempotencyScope(): string
    {
        return sprintf('organization:%d:project:%d:roadmap:%d:edit', $this->organizationId, $this->projectId, $this->roadmapId);
    }

    /** @return array<string, mixed> */
    public function idempotencyPayload(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'project_id' => $this->projectId,
            'roadmap_id' => $this->roadmapId,
            'actor_user_id' => $this->actorUserId,
            'expected_content_version' => $this->expectedContentVersion,
            'expected_fingerprint' => $this->expectedFingerprint,
            'patch_fingerprint' => RoadmapCommandFingerprint::make($this->patch),
        ];
    }

    public function persistedIdempotencyHash(): string
    {
        return hash('sha256', $this->requestIdempotencyKey);
    }
}
