<?php

declare(strict_types=1);

namespace App\Http\Controllers\Approvals;

use App\Application\Approvals\GetProjectApprovalInbox;
use App\Http\Controllers\Controller;
use App\Http\Requests\Approvals\ProjectApprovalInboxRequest;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Renders the tenant-scoped human decision inbox.
 */
final class ProjectApprovalInboxController extends Controller
{
    /**
     * Display normalized pending decisions and their exact action contexts.
     */
    public function __invoke(
        ProjectApprovalInboxRequest $request,
        Organization $organization,
        Project $project,
        GetProjectApprovalInbox $inbox,
    ): Response {
        $filters = $request->filters();
        $actor = $request->user();

        return Inertia::render('projects/approvals/index', [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
            ],
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'slug' => $project->slug,
                'status' => $project->status->value,
                'terminal' => $project->status->isTerminal(),
            ],
            'projectUrl' => route(
                'organizations.projects.show',
                [
                    'organization' => $organization,
                    'project' => $project,
                ],
            ),
            'operationsUrl' => route(
                'organizations.projects.operations.index',
                [
                    'organization' => $organization,
                    'project' => $project,
                ],
            ),
            'inboxUrl' => route(
                'organizations.projects.approvals.index',
                [
                    'organization' => $organization,
                    'project' => $project,
                ],
            ),
            'filters' => $filters,
            'canApprove' => $actor instanceof User
                && $actor->can('approve', $project),
            'inbox' => $inbox->handle(
                organizationId: $organization->id,
                projectId: $project->id,
                category: $filters['category'],
                urgency: $filters['urgency'],
                search: $filters['search'],
            ),
        ]);
    }
}
