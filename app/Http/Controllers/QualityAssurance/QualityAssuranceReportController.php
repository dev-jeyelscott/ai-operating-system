<?php

declare(strict_types=1);

namespace App\Http\Controllers\QualityAssurance;

use App\Application\QualityAssurance\GetProjectQualityAssuranceReport;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Renders the read-only project QA report and merge-risk matrix.
 */
final class QualityAssuranceReportController extends Controller
{
    /**
     * Display the latest project-scoped Layer 3 assessment.
     */
    public function __invoke(
        Request $request,
        Organization $organization,
        Project $project,
        GetProjectQualityAssuranceReport $report,
    ): Response {
        return Inertia::render('projects/quality-assurance/show', [
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
            'projectUrl' => route('organizations.projects.show', [
                'organization' => $organization,
                'project' => $project,
            ]),
            'report' => Inertia::defer(
                fn (): array => $report->handle(
                    organizationId: $organization->id,
                    projectId: $project->id,
                    actorUserId: $request->user()?->id,
                ),
                rescue: true,
            ),
        ]);
    }
}
