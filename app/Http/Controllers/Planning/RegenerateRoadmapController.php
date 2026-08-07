<?php

declare(strict_types=1);

namespace App\Http\Controllers\Planning;

use App\Application\Planning\Commands\RegenerateRoadmapCommand;
use App\Application\Shared\Commands\CommandBus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Planning\RegenerateRoadmapRequest;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Roadmap;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

class RegenerateRoadmapController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(RegenerateRoadmapRequest $request, Organization $organization, Project $project, Roadmap $roadmap, CommandBus $commands): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $validated = $request->validated();
        $result = $commands->dispatch(new RegenerateRoadmapCommand(
            organizationId: $organization->id,
            projectId: $project->id,
            roadmapId: $roadmap->id,
            actorUserId: $actor->id,
            expectedContentVersion: (int) $validated['expected_content_version'],
            expectedFingerprint: (string) $validated['expected_fingerprint'],
            feedback: (string) $validated['feedback'],
            requestIdempotencyKey: (string) $validated['idempotency_key'],
            correlationId: (string) Str::ulid(),
        ));
        if (! $result->isSuccessful()) {
            return back()->withErrors(['roadmap' => $result->message ?? 'The roadmap regeneration could not be requested.']);
        }

        return to_route('organizations.projects.roadmaps.index', compact('organization', 'project'))->with('status', 'roadmap-regeneration-requested');
    }
}
