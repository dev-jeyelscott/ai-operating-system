<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Application\Projects\GetProjectConfigurationOverview;
use App\Domain\Projects\ProjectSetupStep;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Delivers the read-only project settings and integrations screens.
 */
final class ProjectConfigurationController extends Controller
{
    /**
     * Render persisted project settings and completeness results.
     */
    public function settings(
        Request $request,
        Organization $organization,
        Project $project,
        GetProjectConfigurationOverview $getOverview,
    ): Response {
        return $this->render(
            request: $request,
            organization: $organization,
            project: $project,
            component: 'projects/settings',
            getOverview: $getOverview,
        );
    }

    /**
     * Render safe project integration and credential metadata.
     */
    public function integrations(
        Request $request,
        Organization $organization,
        Project $project,
        GetProjectConfigurationOverview $getOverview,
    ): Response {
        return $this->render(
            request: $request,
            organization: $organization,
            project: $project,
            component: 'projects/integrations',
            getOverview: $getOverview,
        );
    }

    /**
     * Build the common server-authoritative Inertia response.
     */
    private function render(
        Request $request,
        Organization $organization,
        Project $project,
        string $component,
        GetProjectConfigurationOverview $getOverview,
    ): Response {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        return Inertia::render($component, [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
            ],
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'slug' => $project->slug,
                'archivedAt' => $project->archived_at?->toIso8601String(),
            ],
            ...$getOverview->handle(
                organizationId: $organization->id,
                projectId: $project->id,
            ),
            'permissions' => [
                'update' => $user->can('update', $project),
                'manageIntegrations' => $user->can(
                    'manageIntegrations',
                    $project,
                ),
            ],
            'urls' => [
                'project' => route('organizations.projects.show', [
                    'organization' => $organization,
                    'project' => $project,
                ]),
                'settings' => route(
                    'organizations.projects.settings.show',
                    [
                        'organization' => $organization,
                        'project' => $project,
                    ],
                ),
                'integrations' => route(
                    'organizations.projects.integrations.index',
                    [
                        'organization' => $organization,
                        'project' => $project,
                    ],
                ),
                'setup' => route(
                    'organizations.projects.setup.start',
                    [
                        'organization' => $organization,
                        'project' => $project,
                    ],
                ),
                'setupSteps' => $this->setupStepUrls(
                    organization: $organization,
                    project: $project,
                ),
            ],
        ]);
    }

    /**
     * Return an explicit URL for each server-defined setup step.
     *
     * @return array<string, string>
     */
    private function setupStepUrls(
        Organization $organization,
        Project $project,
    ): array {
        $urls = [];

        foreach (ProjectSetupStep::ordered() as $step) {
            $urls[$step->value] = route(
                'organizations.projects.setup.show',
                [
                    'organization' => $organization,
                    'project' => $project,
                    'step' => $step,
                ],
            );
        }

        return $urls;
    }
}
