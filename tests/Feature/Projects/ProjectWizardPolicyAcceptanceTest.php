<?php

declare(strict_types=1);

use App\Domain\Integrations\IntegrationProvider;
use App\Domain\Projects\ProjectSetupStep;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\ProjectConfiguration;
use App\Models\ProjectSetupProgress;
use App\Models\ProviderCredential;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    /*
     * Isolate project-command rate limits so one acceptance test cannot affect
     * another test in the same process.
     */
    config()->set([
        'rate-limits.project_commands.update.per_minute' => 100,
        'rate-limits.project_commands.update.per_hour' => 1000,
    ]);

    Cache::store((string) config('cache.limiter'))->flush();
});

test(
    'wizard validation is server authoritative and does not mutate state',
    function (): void {
        [
            'user' => $user,
            'organization' => $organization,
            'project' => $project,
        ] = aios032ProjectFixture(
            currentStep: ProjectSetupStep::Details,
        );

        $pageUrl = route('organizations.projects.setup.show', [
            'organization' => $organization,
            'project' => $project,
            'step' => ProjectSetupStep::Details,
        ]);

        $this->actingAs($user)
            ->from($pageUrl)
            ->put(route('organizations.projects.setup.update', [
                'organization' => $organization,
                'project' => $project,
                'step' => ProjectSetupStep::Details,
            ]), [
                'languages' => '',
                'frameworks' => 'Laravel 13, React',
                'databases' => 'PostgreSQL, Redis',
                'infrastructure' => 'Docker Compose',
                'package_managers' => 'Composer, pnpm',
                'runtimes' => 'PHP 8.5, Node.js 24',
            ])
            ->assertRedirect($pageUrl)
            ->assertSessionHasErrors('technology_stack.languages');

        $configuration = $project
            ->configuration()
            ->firstOrFail();

        $progress = $project
            ->setupProgress()
            ->firstOrFail();

        expect($configuration->revision)
            ->toBe(1)
            ->and($configuration->technology_stack['languages'])
            ->toBe([])
            ->and($progress->current_step)
            ->toBe(ProjectSetupStep::Details)
            ->and($progress->completed_steps)
            ->toBe([]);
    },
);

test(
    'invalid policy input does not mutate configuration or wizard progress',
    function (): void {
        [
            'user' => $user,
            'organization' => $organization,
            'project' => $project,
        ] = aios032ProjectFixture(
            currentStep: ProjectSetupStep::Policies,
            completedSteps: [
                ProjectSetupStep::Details,
                ProjectSetupStep::Repository,
                ProjectSetupStep::Integrations,
                ProjectSetupStep::Commands,
            ],
        );

        $configurationBefore = $project
            ->configuration()
            ->firstOrFail()
            ->getAttributes();

        $progressBefore = $project
            ->setupProgress()
            ->firstOrFail()
            ->getAttributes();

        $pageUrl = route('organizations.projects.setup.show', [
            'organization' => $organization,
            'project' => $project,
            'step' => ProjectSetupStep::Policies,
        ]);

        $this->actingAs($user)
            ->from($pageUrl)
            ->put(route('organizations.projects.setup.update', [
                'organization' => $organization,
                'project' => $project,
                'step' => ProjectSetupStep::Policies,
            ]), [
                'required_documents' => implode(', ', [
                    'product_charter',
                    'requirements',
                    'architecture',
                ]),
                'default_reasoning' => 'medium',
                'allowed_provider_ids' => 'simulation, openai',

                /*
                 * Anthropic is not included in the approved provider allowlist.
                 */
                'fallback_order' => 'openai, anthropic',

                'budget_limit_minor' => 10000,
                'budget_currency' => 'USD',
                'automatic_retry_limit' => 3,
                'autonomy_level' => 'approval_required',
                'roadmap_required' => true,
                'ticket_execution_required' => true,
                'merge_required' => true,
                'notification_events' => implode(', ', [
                    'roadmap.ready',
                    'approval.requested',
                ]),
            ])
            ->assertRedirect($pageUrl)
            ->assertSessionHasErrors(
                'provider_policy.fallback_order',
            );

        expect(
            $project
                ->configuration()
                ->firstOrFail()
                ->getAttributes(),
        )
            ->toBe($configurationBefore)
            ->and(
                $project
                    ->setupProgress()
                    ->firstOrFail()
                    ->getAttributes(),
            )
            ->toBe($progressBefore);
    },
);

test(
    'viewers cannot mutate project wizard configuration',
    function (): void {
        [
            'user' => $user,
            'organization' => $organization,
            'project' => $project,
        ] = aios032ProjectFixture(
            currentStep: ProjectSetupStep::Details,
            viewer: true,
        );

        $this->actingAs($user)
            ->put(route('organizations.projects.setup.update', [
                'organization' => $organization,
                'project' => $project,
                'step' => ProjectSetupStep::Details,
            ]), [
                'languages' => 'PHP, TypeScript',
                'frameworks' => 'Laravel 13, React',
                'databases' => 'PostgreSQL, Redis',
                'infrastructure' => 'Docker Compose',
                'package_managers' => 'Composer, pnpm',
                'runtimes' => 'PHP 8.5, Node.js 24',
            ])
            ->assertForbidden();

        expect(
            $project
                ->configuration()
                ->firstOrFail()
                ->revision,
        )
            ->toBe(1)
            ->and(
                $project
                    ->setupProgress()
                    ->firstOrFail()
                    ->completed_steps,
            )
            ->toBe([]);
    },
);

test(
    'main is rejected as the automated integration branch',
    function (): void {
        [
            'user' => $user,
            'organization' => $organization,
            'project' => $project,
        ] = aios032ProjectFixture(
            currentStep: ProjectSetupStep::Repository,
            completedSteps: [
                ProjectSetupStep::Details,
            ],
        );

        $pageUrl = route('organizations.projects.setup.show', [
            'organization' => $organization,
            'project' => $project,
            'step' => ProjectSetupStep::Repository,
        ]);

        $this->actingAs($user)
            ->from($pageUrl)
            ->put(route('organizations.projects.setup.update', [
                'organization' => $organization,
                'project' => $project,
                'step' => ProjectSetupStep::Repository,
            ]), [
                'repository_provider' => 'github',
                'repository_url' => 'https://github.com/example/project',
                'default_branch' => 'main',
                'integration_branch' => 'main',
            ])
            ->assertRedirect($pageUrl)
            ->assertSessionHasErrors('integration_branch');

        $configuration = $project
            ->configuration()
            ->firstOrFail();

        expect($configuration->integration_branch)
            ->toBe('develop')
            ->and($configuration->revision)
            ->toBe(1);
    },
);

test(
    'wizard responses expose credential state without exposing the secret',
    function (): void {
        [
            'user' => $user,
            'organization' => $organization,
            'project' => $project,
        ] = aios032ProjectFixture(
            currentStep: ProjectSetupStep::Integrations,
            completedSteps: [
                ProjectSetupStep::Details,
                ProjectSetupStep::Repository,
            ],
        );

        $ciphertext = 'encrypted-aios-032-notion-credential';

        ProviderCredential::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'provider' => IntegrationProvider::Notion,
            'secret_ciphertext' => $ciphertext,
            'version' => 1,
            'created_by_user_id' => $user->id,
            'last_rotated_by_user_id' => null,
            'rotated_at' => null,
        ]);

        $response = $this->actingAs($user)
            ->get(route('organizations.projects.setup.show', [
                'organization' => $organization,
                'project' => $project,
                'step' => ProjectSetupStep::Integrations,
            ]));

        $response
            ->assertOk()
            ->assertInertia(
                fn (Assert $page): Assert => $page
                    ->component('projects/setup')
                    ->where('activeStep', 'integrations')
                    ->has(
                        'integration',
                        fn (Assert $integration): Assert => $integration
                            ->where('provider', 'notion')
                            ->where('credentialConfigured', true)
                            ->missing('credential')
                            ->missing('secret')
                            ->missing('secretCiphertext')
                            ->etc(),
                    ),
            )
            ->assertDontSee($ciphertext, false)
            ->assertDontSee('secret_ciphertext', false);
    },
);

/**
 * Create an organization-scoped Phase 2 project fixture at a specific wizard
 * position.
 *
 * @param  list<ProjectSetupStep>  $completedSteps
 * @return array{
 *     user: User,
 *     organization: Organization,
 *     project: Project
 * }
 */
function aios032ProjectFixture(
    ProjectSetupStep $currentStep,
    array $completedSteps = [],
    bool $viewer = false,
): array {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    $membership = OrganizationMembership::factory()
        ->for($organization)
        ->for($user);

    $viewer
        ? $membership->viewer()->create()
        : $membership->owner()->create();

    $project = Project::factory()
        ->for($organization)
        ->create();

    ProjectConfiguration::factory()
        ->for($project)
        ->create([
            /*
             * Preserve the deterministic repository-policy default.
             */
            'integration_branch' => 'develop',
        ]);

    ProjectSetupProgress::query()->create([
        'project_id' => $project->id,
        'current_step' => $currentStep,
        'completed_steps' => array_map(
            static fn (ProjectSetupStep $step): string => $step->value,
            $completedSteps,
        ),
        'completed_at' => null,
    ]);

    return [
        'user' => $user,
        'organization' => $organization,
        'project' => $project,
    ];
}
