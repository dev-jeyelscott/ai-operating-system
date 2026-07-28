<?php

declare(strict_types=1);

namespace App\Http\Controllers\Planning;

use App\Http\Controllers\Controller;
use App\Http\Requests\Planning\PublishRoadmapToNotionRequest;
use App\Jobs\PublishRoadmapToNotionJob;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Roadmap;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

final class PublishRoadmapToNotionController extends Controller
{
    public function __invoke(PublishRoadmapToNotionRequest $request, Organization $organization, Project $project, Roadmap $roadmap): RedirectResponse
    {
        abort_unless($roadmap->project_id === $project->id, 404);
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        PublishRoadmapToNotionJob::dispatch(
            actorUserId: $actor->id,
            organizationId: $organization->id,
            roadmapId: $roadmap->id,
            idempotencyKey: (string) $request->validated('idempotency_key'),
            correlationId: (string) $request->attributes->get('request_id'),
        );

        return back()->with('status', 'notion-publication-queued');
    }
}
