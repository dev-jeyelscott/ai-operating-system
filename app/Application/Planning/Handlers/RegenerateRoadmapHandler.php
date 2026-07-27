<?php

declare(strict_types=1);

namespace App\Application\Planning\Handlers;

use App\Application\Planning\Commands\RegenerateRoadmapCommand;
use App\Application\Planning\RegenerateRoadmap;
use App\Application\Shared\Commands\CommandResult;
use App\Models\Project;
use App\Models\Roadmap;
use App\Models\User;

final readonly class RegenerateRoadmapHandler
{
    public function __construct(private RegenerateRoadmap $regenerate) {}

    public function handle(RegenerateRoadmapCommand $command): CommandResult
    {
        $project = Project::query()->forOrganization($command->organizationId)->whereKey($command->projectId)->firstOrFail();
        $roadmap = Roadmap::query()->whereBelongsTo($project)->whereKey($command->roadmapId)->firstOrFail();
        $actor = User::query()->findOrFail($command->actorUserId);
        $execution = $this->regenerate->handle(
            roadmap: $roadmap,
            actor: $actor,
            expectedContentVersion: $command->expectedContentVersion,
            expectedFingerprint: $command->expectedFingerprint,
            feedback: $command->feedback,
            idempotencyKey: $command->requestIdempotencyKey,
            correlationId: $command->correlationId,
        );

        return CommandResult::succeeded([
            'roadmap_id' => $roadmap->id,
            'execution_id' => $execution->id,
        ]);
    }
}
