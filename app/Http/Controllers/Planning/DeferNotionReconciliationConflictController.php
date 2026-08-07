<?php

declare(strict_types=1);

namespace App\Http\Controllers\Planning;

use App\Application\Planning\Notion\DeferNotionReconciliationConflict;
use App\Http\Controllers\Controller;
use App\Http\Requests\Planning\DecideNotionReconciliationConflictRequest;
use App\Models\NotionReconciliationConflict;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

final class DeferNotionReconciliationConflictController extends Controller
{
    public function __invoke(DecideNotionReconciliationConflictRequest $request, Organization $organization, Project $project, NotionReconciliationConflict $conflict, DeferNotionReconciliationConflict $defer): RedirectResponse
    {
        abort_unless($conflict->organization_id === $organization->id && $conflict->project_id === $project->id, 404);
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $validated = $request->validated();
        $defer->handle($conflict, $actor, (string) $validated['expected_fingerprint'], (string) $validated['reason'], (string) $request->attributes->get('request_id'));

        return back()->with('status', 'notion-conflict-deferred');
    }
}
