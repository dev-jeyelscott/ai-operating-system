<?php

declare(strict_types=1);

namespace App\Application\Planning\Handlers;

use App\Application\Planning\Commands\EditRoadmapCommand;
use App\Application\Planning\EditRoadmap;
use App\Application\Shared\Commands\CommandResult;
use App\Models\Project;
use App\Models\Roadmap;
use App\Models\User;

final readonly class EditRoadmapHandler
{
    public function __construct(private EditRoadmap $edit) {}

    public function handle(EditRoadmapCommand $command): CommandResult
    {
        $project = Project::query()->forOrganization($command->organizationId)->whereKey($command->projectId)->firstOrFail();
        $roadmap = Roadmap::query()->whereBelongsTo($project)->whereKey($command->roadmapId)->firstOrFail();
        $actor = User::query()->findOrFail($command->actorUserId);
        $updated = $this->edit->handle(
            roadmap: $roadmap,
            actor: $actor,
            expectedContentVersion: $command->expectedContentVersion,
            expectedFingerprint: $command->expectedFingerprint,
            patch: $command->patch,
            idempotencyKey: $command->requestIdempotencyKey,
            correlationId: $command->correlationId,
        );

        return CommandResult::succeeded([
            'roadmap_id' => $updated->id,
            'content_version' => $updated->content_version,
            'candidate_fingerprint' => $updated->candidate_fingerprint,
        ]);
    }
}
