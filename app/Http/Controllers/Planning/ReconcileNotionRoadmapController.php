<?php

declare(strict_types=1);

namespace App\Http\Controllers\Planning;

use App\Application\Planning\Notion\ReconcileNotionRoadmap;
use App\Http\Controllers\Controller;
use App\Http\Requests\Planning\ReconcileNotionRoadmapRequest;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Roadmap;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

final class ReconcileNotionRoadmapController extends Controller
{
    public function __invoke(ReconcileNotionRoadmapRequest $request, Organization $organization, Project $project, Roadmap $roadmap, ReconcileNotionRoadmap $reconcile): RedirectResponse
    {
        abort_unless($roadmap->project_id === $project->id, 404);
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $reconcile->handle($actor->id, $organization->id, $roadmap, (string) $request->attributes->get('request_id'));

        return back()->with('status', 'notion-reconciliation-completed');
    }
}
