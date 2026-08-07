<?php

declare(strict_types=1);

namespace App\Http\Controllers\Planning;

use App\Application\Planning\Notion\RetainInternalNotionConflict;
use App\Http\Controllers\Controller;
use App\Http\Requests\Planning\DecideNotionReconciliationConflictRequest;
use App\Jobs\RepublishNotionConflictJob;
use App\Models\NotionReconciliationConflict;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

final class RetainInternalNotionConflictController extends Controller
{
    public function __invoke(DecideNotionReconciliationConflictRequest $request, Organization $organization, Project $project, NotionReconciliationConflict $conflict, RetainInternalNotionConflict $retain): RedirectResponse
    {
        abort_unless($conflict->organization_id === $organization->id && $conflict->project_id === $project->id, 404);
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $validated = $request->validated();
        $resolved = $retain->handle($conflict, $actor, (string) $validated['expected_fingerprint'], (string) $validated['reason'], (string) $request->attributes->get('request_id'));
        if ($resolved->state === 'republish_queued') {
            RepublishNotionConflictJob::dispatch($resolved->id, $actor->id, $organization->id, (string) $request->attributes->get('request_id'))->afterCommit();
        }

        return back()->with('status', 'notion-conflict-republish-queued');
    }
}
