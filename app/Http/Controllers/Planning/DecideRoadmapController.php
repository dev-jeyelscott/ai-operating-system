<?php

declare(strict_types=1);

namespace App\Http\Controllers\Planning;

use App\Application\Planning\Commands\DecideRoadmapCommand;
use App\Application\Shared\Commands\CommandBus;
use App\Domain\Approvals\ApprovalDecision;
use App\Http\Controllers\Controller;
use App\Http\Requests\Planning\DecideRoadmapRequest;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Roadmap;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

class DecideRoadmapController extends Controller
{
    public function approve(DecideRoadmapRequest $request, Organization $organization, Project $project, Roadmap $roadmap, CommandBus $commands): RedirectResponse
    {
        return $this->decide($request, $organization, $project, $roadmap, $commands, ApprovalDecision::Approve);
    }

    public function reject(DecideRoadmapRequest $request, Organization $organization, Project $project, Roadmap $roadmap, CommandBus $commands): RedirectResponse
    {
        return $this->decide($request, $organization, $project, $roadmap, $commands, ApprovalDecision::Reject);
    }

    private function decide(DecideRoadmapRequest $request, Organization $organization, Project $project, Roadmap $roadmap, CommandBus $commands, ApprovalDecision $decision): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $validated = $request->validated();
        $result = $commands->dispatch(new DecideRoadmapCommand(
            organizationId: $organization->id,
            projectId: $project->id,
            roadmapId: $roadmap->id,
            actorUserId: $actor->id,
            decision: $decision,
            expectedContentVersion: (int) $validated['expected_content_version'],
            expectedFingerprint: (string) $validated['expected_fingerprint'],
            requestIdempotencyKey: (string) $validated['idempotency_key'],
            correlationId: (string) Str::ulid(),
            reason: isset($validated['reason']) ? (string) $validated['reason'] : null,
        ));

        if (! $result->isSuccessful()) {
            return back()->withErrors(['roadmap' => $result->message ?? 'The roadmap decision could not be applied.']);
        }

        return back()->with('status', $decision === ApprovalDecision::Approve ? 'roadmap-approved' : 'roadmap-rejected');
    }
}
