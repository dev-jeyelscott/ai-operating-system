<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Application\Projects\SaveProjectSetupStep;
use App\Domain\Integrations\IntegrationProvider;
use App\Domain\Projects\ProjectSetupStep;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\UpdateProjectSetupStepRequest;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectConfiguration;
use App\Models\ProjectIntegration;
use App\Models\ProjectSetupProgress;
use App\Models\ProviderCredential;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Delivers the organization-scoped project setup wizard.
 */
final class ProjectSetupController extends Controller
{
    /**
     * Redirect to the project's last persisted wizard position.
     */
    public function start(
        Organization $organization,
        Project $project,
    ): RedirectResponse {
        $progress = $this->progressFor($project);

        return to_route('organizations.projects.setup.show', [
            'organization' => $organization,
            'project' => $project,
            'step' => $progress->current_step,
        ]);
    }

    /**
     * Render one server-controlled project setup step.
     */
    public function show(
        Organization $organization,
        Project $project,
        ProjectSetupStep $step,
    ): Response|RedirectResponse {
        $progress = $this->progressFor($project);

        /*
         * Do not allow URL manipulation to open a future step before the
         * project has reached it.
         */
        if (
            $step->position() > $progress->current_step->position()
            && ! $progress->hasCompleted($step)
        ) {
            return to_route('organizations.projects.setup.show', [
                'organization' => $organization,
                'project' => $project,
                'step' => $progress->current_step,
            ]);
        }

        $configuration = ProjectConfiguration::query()
            ->where('project_id', $project->id)
            ->firstOrFail();

        $notionIntegration = ProjectIntegration::query()
            ->forOrganization($organization->id)
            ->forProject($project->id)
            ->where(
                'provider',
                IntegrationProvider::Notion->value,
            )
            ->first();

        $notionCredentialConfigured =
            ProviderCredential::query()
                ->forOrganization($organization->id)
                ->forProject($project->id)
                ->where(
                    'provider',
                    IntegrationProvider::Notion->value,
                )
                ->exists();

        return Inertia::render('projects/setup', [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
            ],
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'slug' => $project->slug,
            ],
            'activeStep' => $step->value,
            'steps' => $this->serializeSteps(
                organization: $organization,
                project: $project,
                progress: $progress,
            ),
            'configuration' => $this->serializeConfiguration($configuration),
            'integration' => [
                'provider' => IntegrationProvider::Notion->value,
                'credentialConfigured' => $notionCredentialConfigured,
                'status' => $notionIntegration?->connection_status->value,
                'workspaceId' => $notionIntegration?->workspace_id,
                'workspaceName' => $notionIntegration?->workspace_name,
                'databaseId' => $notionIntegration?->database_id,
                'databaseName' => $notionIntegration?->database_name,
                'lastFailureCode' => $notionIntegration?->last_failure_code?->value,
                'lastTestedAt' => $notionIntegration?->last_tested_at
                    ->toIso8601String(),
                'lastConnectedAt' => $notionIntegration?->last_connected_at
                    ?->toIso8601String(),
            ],
            'progress' => [
                'currentStep' => $progress->current_step->value,
                'completedSteps' => $progress->completed_steps,
                'completedAt' => $progress->completed_at?->toIso8601String(),
            ],
            'urls' => [
                'update' => $step === ProjectSetupStep::Integrations
                    ? route(
                        'organizations.projects.integrations.notion.test',
                        [
                            'organization' => $organization,
                            'project' => $project,
                        ],
                    )
                    : route(
                        'organizations.projects.setup.update',
                        [
                            'organization' => $organization,
                            'project' => $project,
                            'step' => $step,
                        ],
                    ),

                'method' => $step === ProjectSetupStep::Integrations
                    ? 'post'
                    : 'put',
                'project' => route(
                    'organizations.projects.show',
                    [
                        'organization' => $organization,
                        'project' => $project,
                    ],
                ),
            ],
        ]);
    }

    /**
     * Persist the validated step and advance to the next server-owned step.
     */
    public function update(
        UpdateProjectSetupStepRequest $request,
        Organization $organization,
        Project $project,
        ProjectSetupStep $step,
        SaveProjectSetupStep $saveProjectSetupStep,
    ): RedirectResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $correlationId = $request->attributes->get('request_id');

        $progress = $saveProjectSetupStep->handle(
            actorUserId: $user->id,
            organizationId: $organization->id,
            projectId: $project->id,
            step: $step,
            payload: $request->validated(),
            correlationId: is_string($correlationId)
                ? $correlationId
                : null,
        );

        if ($progress->isComplete()) {
            return to_route('organizations.projects.show', [
                'organization' => $organization,
                'project' => $project,
            ])->with('status', 'project-setup-completed');
        }

        return to_route('organizations.projects.setup.show', [
            'organization' => $organization,
            'project' => $project,
            'step' => $progress->current_step,
        ])->with('status', 'project-setup-step-saved');
    }

    /**
     * Return existing setup progress or initialize it for legacy projects.
     */
    private function progressFor(
        Project $project,
    ): ProjectSetupProgress {
        ProjectSetupProgress::query()->insertOrIgnore([
            'project_id' => $project->id,
            'current_step' => ProjectSetupStep::Details->value,
            'completed_steps' => '[]',
            'completed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ProjectSetupProgress::query()
            ->where('project_id', $project->id)
            ->firstOrFail();
    }

    /**
     * Serialize ordered wizard navigation.
     *
     * @return list<array{
     *     value: string,
     *     label: string,
     *     description: string,
     *     completed: bool,
     *     active: bool,
     *     canVisit: bool,
     *     href: string
     * }>
     */
    private function serializeSteps(
        Organization $organization,
        Project $project,
        ProjectSetupProgress $progress,
    ): array {
        return array_map(
            static fn (ProjectSetupStep $step): array => [
                'value' => $step->value,
                'label' => $step->label(),
                'description' => $step->description(),
                'completed' => $progress->hasCompleted($step),
                'active' => $step === $progress->current_step,
                'canVisit' => $step->position()
                    <= $progress->current_step->position()
                    || $progress->hasCompleted($step),
                'href' => route(
                    'organizations.projects.setup.show',
                    [
                        'organization' => $organization,
                        'project' => $project,
                        'step' => $step,
                    ],
                ),
            ],
            ProjectSetupStep::ordered()
        );
    }

    /**
     * Serialize only non-secret configuration metadata.
     *
     * @return array<string, mixed>
     */
    private function serializeConfiguration(
        ProjectConfiguration $configuration,
    ): array {
        return [
            'revision' => $configuration->revision,
            'technologyStack' => $configuration->technology_stack,
            'repository' => [
                'provider' => $configuration->repository_provider?->value,
                'url' => $configuration->repository_url,
                'defaultBranch' => $configuration->default_branch,
                'integrationBranch' => $configuration->integration_branch,
            ],
            'commands' => [
                'build' => $configuration->build_command,
                'test' => $configuration->test_command,
                'lint' => $configuration->lint_command,
                'staticAnalysis' => $configuration->static_analysis_command,
                'security' => $configuration->security_command,
            ],
            'policy' => [
                'defaultReasoning' => $configuration->default_reasoning->value,
                'provider' => $configuration->provider_policy,
                'budgetLimitMinor' => $configuration->budget_limit_minor,
                'budgetCurrency' => $configuration->budget_currency,
                'automaticRetryLimit' => $configuration->automatic_retry_limit,
                'autonomyLevel' => $configuration->autonomy_level->value,
                'approval' => $configuration->approval_policy,
                'notification' => $configuration->notification_policy,
            ],
        ];
    }
}
