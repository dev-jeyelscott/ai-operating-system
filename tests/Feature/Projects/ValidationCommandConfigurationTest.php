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

beforeEach(function (): void {
    /*
     * Isolate privileged-command rate-limit counters between tests.
     */
    Cache::store((string) config('cache.limiter'))->flush();

    [
        $this->user,
        $this->organization,
        $this->project,
    ] = validationCommandProjectFixture();

    /*
     * Complete the required Details step.
     */
    $this->actingAs($this->user)
        ->put(route('organizations.projects.setup.update', [
            'organization' => $this->organization,
            'project' => $this->project,
            'step' => ProjectSetupStep::Details,
        ]), [
            'languages' => 'PHP, TypeScript',
            'frameworks' => 'Laravel 13, Inertia.js 3, React',
            'databases' => 'PostgreSQL, Redis',
            'infrastructure' => 'Docker Compose, GitHub Actions',
            'package_managers' => 'Composer, pnpm',
            'runtimes' => 'PHP 8.5, Node.js 22',
        ])
        ->assertRedirect(route(
            'organizations.projects.setup.show',
            [
                'organization' => $this->organization,
                'project' => $this->project,
                'step' => ProjectSetupStep::Repository,
            ],
        ))
        ->assertSessionHasNoErrors();

    /*
     * Complete the required Repository step.
     */
    $this->put(route('organizations.projects.setup.update', [
        'organization' => $this->organization,
        'project' => $this->project,
        'step' => ProjectSetupStep::Repository,
    ]), [
        'repository_provider' => 'github',
        'repository_url' => 'https://github.com/example/project',
        'default_branch' => 'main',
        'integration_branch' => 'develop',
    ])
        ->assertRedirect(route(
            'organizations.projects.setup.show',
            [
                'organization' => $this->organization,
                'project' => $this->project,
                'step' => ProjectSetupStep::Integrations,
            ],
        ))
        ->assertSessionHasNoErrors();

    /*
     * These tests concern validation-command persistence, not the external
     * Notion transport, so arrange the completed integration prerequisite.
     */
    completeProjectIntegrationSetupForTesting($this->project);

    $this->commandUpdateUrl = route(
        'organizations.projects.setup.update',
        [
            'organization' => $this->organization,
            'project' => $this->project,
            'step' => ProjectSetupStep::Commands,
        ],
    );

    $this->policiesPageUrl = route(
        'organizations.projects.setup.show',
        [
            'organization' => $this->organization,
            'project' => $this->project,
            'step' => ProjectSetupStep::Policies,
        ],
    );
});

/**
 * Return a complete valid validation-command payload.
 *
 * @return array{
 *     build_command: string,
 *     test_command: string,
 *     lint_command: string,
 *     static_analysis_command: string,
 *     security_command: string
 * }
 */
function validProjectValidationCommandPayload(): array
{
    return [
        'build_command' => 'pnpm build',
        'test_command' => 'composer test && pnpm test:unit',
        'lint_command' => 'composer lint:check && pnpm lint:check',
        'static_analysis_command' => 'composer types:check && pnpm types:check',
        'security_command' => 'composer audit',
    ];
}

/**
 * Return a valid command payload with one field replaced by an invalid value.
 *
 * @param  'build_command'|'test_command'|'lint_command'|'static_analysis_command'|'security_command'  $field
 * @return array<string, mixed>
 */
function invalidProjectValidationCommandPayload(
    string $field,
    mixed $invalidValue,
): array {
    return array_replace(
        validProjectValidationCommandPayload(),
        [$field => $invalidValue],
    );
}

test(
    'validation commands are normalized, stored, and advance setup progress',
    function (): void {
        // ...
    },
);

test(
    'validation commands are normalized stored and advance setup progress',
    function (): void {
        $payload = validProjectValidationCommandPayload();

        $payload['build_command'] = '  pnpm build  ';
        $payload['security_command'] = '  composer audit  ';

        $this->put($this->commandUpdateUrl, $payload)
            ->assertRedirect($this->policiesPageUrl)
            ->assertSessionHasNoErrors();

        $configuration = $this->project
            ->configuration()
            ->firstOrFail();

        $progress = $this->project
            ->setupProgress()
            ->firstOrFail();

        expect($configuration->revision)
            ->toBe(4)
            ->and($configuration->build_command)
            ->toBe('pnpm build')
            ->and($configuration->test_command)
            ->toBe('composer test && pnpm test:unit')
            ->and($configuration->lint_command)
            ->toBe('composer lint:check && pnpm lint:check')
            ->and($configuration->static_analysis_command)
            ->toBe('composer types:check && pnpm types:check')
            ->and($configuration->security_command)
            ->toBe('composer audit')
            ->and($progress->current_step)
            ->toBe(ProjectSetupStep::Policies)
            ->and($progress->hasCompleted(ProjectSetupStep::Commands))
            ->toBeTrue();
    },
);

test(
    'invalid validation commands do not mutate configuration or progress',
    function (string $field, mixed $invalidValue): void {
        $payload = validProjectValidationCommandPayload();

        $payload[$field] = $invalidValue;

        $this->from(route(
            'organizations.projects.setup.show',
            [
                'organization' => $this->organization,
                'project' => $this->project,
                'step' => ProjectSetupStep::Commands,
            ],
        ))
            ->put($this->commandUpdateUrl, $payload)
            ->assertSessionHasErrors($field);

        $configuration = $this->project
            ->configuration()
            ->firstOrFail();

        $progress = $this->project
            ->setupProgress()
            ->firstOrFail();

        expect($configuration->revision)
            ->toBe(3)
            ->and($configuration->{$field})
            ->toBeNull()
            ->and($progress->current_step)
            ->toBe(ProjectSetupStep::Commands)
            ->and($progress->hasCompleted(ProjectSetupStep::Commands))
            ->toBeFalse();
    },
)->with([
    'missing build command' => [
        'build_command',
        '',
    ],
    'multiline test command' => [
        'test_command',
        "composer test\npnpm test:unit",
    ],
    'oversized lint command' => [
        'lint_command',
        str_repeat('a', 1001),
    ],
    'embedded null-byte static analysis command' => [
        'static_analysis_command',
        invalidProjectValidationCommandPayload(
            field: 'static_analysis_command',
            invalidValue: "composer\0 types:check",
        ),
    ],
    'non-string security command' => [
        'security_command',
        ['composer audit'],
    ],
]);

test(
    'resubmitting identical normalized commands does not create a revision',
    function (): void {
        $payload = validProjectValidationCommandPayload();

        $this->put($this->commandUpdateUrl, $payload)
            ->assertRedirect($this->policiesPageUrl)
            ->assertSessionHasNoErrors();

        $this->put($this->commandUpdateUrl, $payload)
            ->assertRedirect($this->policiesPageUrl)
            ->assertSessionHasNoErrors();

        expect(
            $this->project->configuration()->firstOrFail()->revision,
        )->toBe(4);

        expect(
            $this->project->setupProgress()->firstOrFail()->completed_steps,
        )->toContain(ProjectSetupStep::Commands->value);
    },
);

/**
 * Create an organization-owned project with versioned configuration and setup
 * progress initialized at the details step.
 *
 * @return array{User, Organization, Project}
 */
function validationCommandProjectFixture(): array
{
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    OrganizationMembership::factory()
        ->for($organization)
        ->for($user)
        ->owner()
        ->create();

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
