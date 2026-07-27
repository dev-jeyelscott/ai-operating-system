<?php

declare(strict_types=1);

namespace App\Http\Controllers\Planning;

use App\Application\Planning\Commands\EditRoadmapCommand;
use App\Application\Shared\Commands\CommandBus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Planning\EditRoadmapRequest;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Roadmap;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

class EditRoadmapController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(EditRoadmapRequest $request, Organization $organization, Project $project, Roadmap $roadmap, CommandBus $commands): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $validated = $request->validated();
        $result = $commands->dispatch(new EditRoadmapCommand(
            organizationId: $organization->id,
            projectId: $project->id,
            roadmapId: $roadmap->id,
            actorUserId: $actor->id,
            expectedContentVersion: (int) $validated['expected_content_version'],
            expectedFingerprint: (string) $validated['expected_fingerprint'],
            patch: $validated['patch'],
            requestIdempotencyKey: (string) $validated['idempotency_key'],
            correlationId: (string) Str::ulid(),
        ));
        if (! $result->isSuccessful()) {
            return back()->withErrors(['roadmap' => $result->message ?? 'The roadmap edit could not be applied.']);
        }

        return back()->with('status', 'roadmap-edited');
    }
}
