<?php

declare(strict_types=1);

use App\Domain\Projects\ProjectSetupStep;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\ProjectConfiguration;
use App\Models\ProjectSetupProgress;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    /*
     * Isolate HTTP rate-limit counters between project setup tests.
     */
    Cache::store((string) config('cache.limiter'))->flush();
});

test('an authorized user can open persisted project setup', function () {
    [$user, $organization, $project] = projectSetupFixture();

    $this->actingAs($user)
        ->get(route('organizations.projects.setup.start', [
            'organization' => $organization,
            'project' => $project,
        ]))
        ->assertRedirect(route(
            'organizations.projects.setup.show',
            [
                'organization' => $organization,
                'project' => $project,
                'step' => ProjectSetupStep::Details,
            ],
        ));

    $this->actingAs($user)
        ->get(route('organizations.projects.setup.show', [
            'organization' => $organization,
            'project' => $project,
            'step' => ProjectSetupStep::Details,
        ]))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page): AssertableInertia => $page
                ->component('projects/setup')
                ->where('activeStep', 'details')
                ->where('progress.currentStep', 'details')
                ->has('steps', 6),
        );
});

test('technology stack validation is server authoritative', function () {
    [$user, $organization, $project] = projectSetupFixture();

    $this->actingAs($user)
        ->from(route('organizations.projects.setup.show', [
            'organization' => $organization,
            'project' => $project,
            'step' => ProjectSetupStep::Details,
        ]))
        ->put(route('organizations.projects.setup.update', [
            'organization' => $organization,
            'project' => $project,
            'step' => ProjectSetupStep::Details,
        ]), [
            'languages' => '',
            'frameworks' => '',
            'databases' => '',
            'infrastructure' => '',
            'package_managers' => '',
            'runtimes' => '',
        ])
        ->assertSessionHasErrors([
            'technology_stack.languages',
        ]);

    expect(
        $project->configuration()->firstOrFail()->revision,
    )->toBe(1);

    expect(
        $project->setupProgress()->firstOrFail()
            ->hasCompleted(ProjectSetupStep::Details),
    )->toBeFalse();
});

test('a valid setup step updates configuration and advances progress', function () {
    [$user, $organization, $project] = projectSetupFixture();

    $this->actingAs($user)
        ->put(route('organizations.projects.setup.update', [
            'organization' => $organization,
            'project' => $project,
            'step' => ProjectSetupStep::Details,
        ]), [
            'languages' => 'PHP, TypeScript',
            'frameworks' => 'Laravel 13, React',
            'databases' => 'PostgreSQL, Redis',
            'infrastructure' => 'Docker Compose, GitHub Actions',
            'package_managers' => 'Composer, pnpm',
            'runtimes' => 'PHP 8.5, Node.js 22',
        ])
        ->assertRedirect(route(
            'organizations.projects.setup.show',
            [
                'organization' => $organization,
                'project' => $project,
                'step' => ProjectSetupStep::Repository,
            ],
        ));

    $configuration = $project->configuration()->firstOrFail();
    $progress = $project->setupProgress()->firstOrFail();

    expect($configuration->revision)
        ->toBe(2)
        ->and($configuration->technology_stack['languages'])
        ->toBe(['PHP', 'TypeScript'])
        ->and($progress->current_step)
        ->toBe(ProjectSetupStep::Repository)
        ->and($progress->hasCompleted(ProjectSetupStep::Details))
        ->toBeTrue();
});

test('repeating an identical step does not create another revision', function () {
    [$user, $organization, $project] = projectSetupFixture();

    $payload = [
        'languages' => 'PHP, TypeScript',
        'frameworks' => 'Laravel 13, React',
        'databases' => 'PostgreSQL, Redis',
        'infrastructure' => 'Docker Compose',
        'package_managers' => 'Composer, pnpm',
        'runtimes' => 'PHP 8.5, Node.js 22',
    ];

    $url = route('organizations.projects.setup.update', [
        'organization' => $organization,
        'project' => $project,
        'step' => ProjectSetupStep::Details,
    ]);

    $repositoryStepUrl = route(
        'organizations.projects.setup.show',
        [
            'organization' => $organization,
            'project' => $project,
            'step' => ProjectSetupStep::Repository,
        ],
    );

    /*
    * The first submission materially changes the empty configuration and moves
    * wizard progress to the repository step.
    */
    $this
        ->actingAs($user)
        ->put($url, $payload)
        ->assertRedirect($repositoryStepUrl)
        ->assertSessionHasNoErrors();

    /*
    * Repeating the same step must succeed without creating another revision.
    *
    * Assert the HTTP response so a future throttle or validation response cannot
    * accidentally make this persistence assertion pass.
    */
    $this
        ->actingAs($user)
        ->put($url, $payload)
        ->assertRedirect($repositoryStepUrl)
        ->assertSessionHasNoErrors();

    expect(
        $project->configuration()->firstOrFail()->revision,
    )->toBe(2);

    expect(
        $project->setupProgress()->firstOrFail()->completed_steps,
    )->toBe(['details']);

    expect(
        $project->configuration()->firstOrFail()->revision,
    )->toBe(2);

    expect(
        $project->setupProgress()->firstOrFail()->completed_steps,
    )->toBe(['details']);
});

test('a user cannot skip required setup steps', function () {
    [$user, $organization, $project] = projectSetupFixture();

    $this->actingAs($user)
        ->put(route('organizations.projects.setup.update', [
            'organization' => $organization,
            'project' => $project,
            'step' => ProjectSetupStep::Repository,
        ]), [
            'repository_provider' => 'github',
            'repository_url' => 'https://github.com/example/project',
            'default_branch' => 'main',
            'integration_branch' => 'develop',
        ])
        ->assertSessionHasErrors('step');

    expect(
        $project->setupProgress()->firstOrFail()->completed_steps,
    )->toBe([]);
});

test('read-only organization members cannot configure projects', function () {
    [$user, $organization, $project] = projectSetupFixture(
        readOnly: true,
    );

    $this->actingAs($user)
        ->get(route('organizations.projects.setup.show', [
            'organization' => $organization,
            'project' => $project,
            'step' => ProjectSetupStep::Details,
        ]))
        ->assertForbidden();
});

test('cross-organization project setup routes do not disclose projects', function () {
    [$user, $organization] = projectSetupFixture();

    $otherOrganization = Organization::factory()->create();

    $otherProject = Project::factory()
        ->for($otherOrganization)
        ->create();

    ProjectConfiguration::factory()
        ->for($otherProject)
        ->create();

    ProjectSetupProgress::query()->create([
        'project_id' => $otherProject->id,
        'current_step' => ProjectSetupStep::Details,
        'completed_steps' => [],
    ]);

    $this->actingAs($user)
        ->get(route('organizations.projects.setup.show', [
            'organization' => $organization,
            'project' => $otherProject,
            'step' => ProjectSetupStep::Details,
        ]))
        ->assertNotFound();
});

test('project setup submissions use the configured update command limit', function () {
    [$user, $organization, $project] = projectSetupFixture();

    /*
     * Permit exactly two setup mutations. This verifies that consecutive
     * wizard steps share the configured update bucket instead of falling into
     * the one-request unknown fallback bucket.
     */
    config()->set(
        'rate-limits.project_commands.update.per_minute',
        2,
    );

    config()->set(
        'rate-limits.project_commands.update.per_hour',
        20,
    );

    $detailsUrl = route('organizations.projects.setup.update', [
        'organization' => $organization,
        'project' => $project,
        'step' => ProjectSetupStep::Details,
    ]);

    /*
     * The first setup step must be persisted successfully.
     */
    $this
        ->actingAs($user)
        ->put($detailsUrl, [
            'languages' => 'PHP, TypeScript',
            'frameworks' => 'Laravel 13, React',
            'databases' => 'PostgreSQL, Redis',
            'infrastructure' => 'Docker Compose, GitHub Actions',
            'package_managers' => 'Composer, pnpm',
            'runtimes' => 'PHP 8.5, Node.js 22',
        ])
        ->assertRedirect(route(
            'organizations.projects.setup.show',
            [
                'organization' => $organization,
                'project' => $project,
                'step' => ProjectSetupStep::Repository,
            ],
        ))
        ->assertSessionHasNoErrors();

    $repositoryUrl = route('organizations.projects.setup.update', [
        'organization' => $organization,
        'project' => $project,
        'step' => ProjectSetupStep::Repository,
    ]);

    /*
     * The second consecutive wizard step must still be permitted.
     *
     * Before the fix, this request resolves to the unknown bucket and is
     * rejected because that missing configuration falls back to one attempt.
     */
    $this
        ->actingAs($user)
        ->put($repositoryUrl, [
            'repository_provider' => 'github',
            'repository_url' => 'https://github.com/example/project',
            'default_branch' => 'main',
            'integration_branch' => 'develop',
        ])
        ->assertRedirect(route(
            'organizations.projects.setup.show',
            [
                'organization' => $organization,
                'project' => $project,
                'step' => ProjectSetupStep::Integrations,
            ],
        ))
        ->assertSessionHasNoErrors();

    $integrationsPage = route('organizations.projects.setup.show', [
        'organization' => $organization,
        'project' => $project,
        'step' => ProjectSetupStep::Integrations,
    ]);

    /*
    * The third generic setup mutation must be rejected because the configured
    * update limit allows only two requests per minute.
    *
    * Re-submit Repository rather than testing Commands because Integrations is
    * now the required intermediate step.
    */
    $this
        ->actingAs($user)
        ->from($integrationsPage)
        ->put(route('organizations.projects.setup.update', [
            'organization' => $organization,
            'project' => $project,
            'step' => ProjectSetupStep::Repository,
        ]), [
            'repository_provider' => 'github',
            'repository_url' => 'https://github.com/example/project',
            'default_branch' => 'main',
            'integration_branch' => 'develop',
        ])
        ->assertRedirect($integrationsPage)
        ->assertHeader('Retry-After')
        ->assertSessionHasErrors('rate_limit');

    /*
     * Only the two permitted submissions may change the configuration.
     */
    expect(
        $project->configuration()->firstOrFail()->revision,
    )->toBe(3);
});

/**
 * Create an organization-scoped project with AIOS-021 configuration.
 *
 * @return array{User, Organization, Project}
 */
function projectSetupFixture(
    bool $readOnly = false,
): array {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    $membership = OrganizationMembership::factory()
        ->for($organization)
        ->for($user);

    $readOnly
        ? $membership->viewer()->create()
        : $membership->owner()->create();

    $project = Project::factory()
        ->for($organization)
        ->create();

    ProjectConfiguration::factory()
        ->for($project)
        ->create();

    ProjectSetupProgress::query()->create([
        'project_id' => $project->id,
        'current_step' => ProjectSetupStep::Details,
        'completed_steps' => [],
    ]);

    return [$user, $organization, $project];
}
