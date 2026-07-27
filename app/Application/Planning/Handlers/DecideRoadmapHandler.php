<?php

declare(strict_types=1);

namespace App\Application\Planning\Handlers;

use App\Application\Planning\Commands\DecideRoadmapCommand;
use App\Application\Planning\DecideRoadmap;
use App\Application\Shared\Commands\CommandResult;
use App\Models\Project;
use App\Models\Roadmap;
use App\Models\User;

final readonly class DecideRoadmapHandler
{
    public function __construct(private DecideRoadmap $decide) {}

    public function handle(DecideRoadmapCommand $command): CommandResult
    {
        $project = Project::query()->forOrganization($command->organizationId)->whereKey($command->projectId)->firstOrFail();
        $roadmap = Roadmap::query()->whereBelongsTo($project)->whereKey($command->roadmapId)->firstOrFail();
        $actor = User::query()->findOrFail($command->actorUserId);

        return $this->decide->handle(
            roadmap: $roadmap,
            actor: $actor,
            decision: $command->decision,
            expectedContentVersion: $command->expectedContentVersion,
            expectedFingerprint: $command->expectedFingerprint,
            idempotencyKey: $command->requestIdempotencyKey,
            correlationId: $command->correlationId,
            reason: $command->reason,
        );
    }
}
