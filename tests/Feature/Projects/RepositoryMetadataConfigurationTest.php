<?php

declare(strict_types=1);

use App\Domain\Projects\ProjectSetupStep;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\ProjectConfiguration;
use App\Models\ProjectSetupProgress;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    /*
     * A repository metadata save must not perform an outbound HTTP request.
     * Any accidental request fails the test immediately.
     */
    Http::preventStrayRequests();
});

test(
    'repository metadata is validated and persisted without remote access',
    function (): void {
        [$user, $organization, $project] =
            repositoryMetadataConfigurationFixture();

        /*
         * The repository intentionally does not need to exist. Successful
         * persistence proves this ticket performs format validation only.
         */
        $payload = [
            'repository_provider' => 'github',
            'repository_url' => implode('', [
                'https://github.com/',
                'does-not-exist-aios/',
                'repository-metadata-test.git',
            ]),
            'default_branch' => 'main',
            'integration_branch' => 'feature/AIOS-023-repository-metadata',
        ];

        $this->actingAs($user)
            ->put(repositoryMetadataUpdateUrl(
                $organization,
                $project,
            ), $payload)
            ->assertRedirect(route(
                'organizations.projects.setup.show',
                [
                    'organization' => $organization,
                    'project' => $project,
                    'step' => ProjectSetupStep::Commands,
                ],
            ));

        $configuration = $project->configuration()->firstOrFail();
        $progress = $project->setupProgress()->firstOrFail();

        expect($configuration->repository_provider?->value)
            ->toBe('github')
            ->and($configuration->repository_url)
            ->toBe($payload['repository_url'])
            ->and($configuration->default_branch)
            ->toBe('main')
            ->and($configuration->integration_branch)
            ->toBe('feature/AIOS-023-repository-metadata')
            ->and($configuration->revision)
            ->toBe(2)
            ->and($progress->hasCompleted(
                ProjectSetupStep::Repository,
            ))
            ->toBeTrue()
            ->and($progress->current_step)
            ->toBe(ProjectSetupStep::Commands);

        Http::assertNothingSent();
    },
);

test(
    'repository metadata is normalized before persistence',
    function (): void {
        [$user, $organization, $project] =
            repositoryMetadataConfigurationFixture();

        $this->actingAs($user)
            ->put(repositoryMetadataUpdateUrl(
                $organization,
                $project,
            ), [
                'repository_provider' => ' GITHUB ',
                'repository_url' => ' https://github.com/example/project ',
                'default_branch' => ' main ',
                'integration_branch' => ' develop ',
            ])
            ->assertSessionHasNoErrors();

        $configuration = $project->configuration()->firstOrFail();

        expect($configuration->repository_provider?->value)
            ->toBe('github')
            ->and($configuration->repository_url)
            ->toBe('https://github.com/example/project')
            ->and($configuration->default_branch)
            ->toBe('main')
            ->and($configuration->integration_branch)
            ->toBe('develop');

        Http::assertNothingSent();
    },
);

test(
    'invalid repository URLs do not modify configuration',
    function (string $repositoryUrl): void {
        [$user, $organization, $project] =
            repositoryMetadataConfigurationFixture();

        $this->actingAs($user)
            ->from(route(
                'organizations.projects.setup.show',
                [
                    'organization' => $organization,
                    'project' => $project,
                    'step' => ProjectSetupStep::Repository,
                ],
            ))
            ->put(repositoryMetadataUpdateUrl(
                $organization,
                $project,
            ), [
                'repository_provider' => 'github',
                'repository_url' => $repositoryUrl,
                'default_branch' => 'main',
                'integration_branch' => 'develop',
            ])
            ->assertSessionHasErrors('repository_url');

        $configuration = $project->configuration()->firstOrFail();
        $progress = $project->setupProgress()->firstOrFail();

        expect($configuration->repository_url)
            ->toBeNull()
            ->and($configuration->revision)
            ->toBe(1)
            ->and($progress->hasCompleted(
                ProjectSetupStep::Repository,
            ))
            ->toBeFalse();

        Http::assertNothingSent();
    },
)->with([
    'HTTP URL' => [
        'http://github.com/example/project',
    ],
    'different provider host' => [
        'https://gitlab.com/example/project',
    ],
    'credentials' => [
        'https://user:token@github.com/example/project',
    ],
    'query string' => [
        'https://github.com/example/project?token=secret',
    ],
    'fragment' => [
        'https://github.com/example/project#readme',
    ],
    'repository subpage' => [
        'https://github.com/example/project/issues',
    ],
]);

test(
    'invalid branch names do not modify configuration',
    function (string $field, string $branch): void {
        [$user, $organization, $project] =
            repositoryMetadataConfigurationFixture();

        $payload = [
            'repository_provider' => 'github',
            'repository_url' => 'https://github.com/example/project',
            'default_branch' => 'main',
            'integration_branch' => 'develop',
        ];

        $payload[$field] = $branch;

        $this->actingAs($user)
            ->put(repositoryMetadataUpdateUrl(
                $organization,
                $project,
            ), $payload)
            ->assertSessionHasErrors($field);

        $configuration = $project->configuration()->firstOrFail();

        expect($configuration->repository_url)
            ->toBeNull()
            ->and($configuration->revision)
            ->toBe(1);

        Http::assertNothingSent();
    },
)->with([
    'default branch containing spaces' => [
        'default_branch',
        'feature invalid',
    ],
    'default branch containing double dots' => [
        'default_branch',
        'release/1.0..2.0',
    ],
    'integration branch containing double slash' => [
        'integration_branch',
        'feature//repository',
    ],
    'integration branch containing reflog syntax' => [
        'integration_branch',
        'feature@{1}',
    ],
    'integration branch ending in lock' => [
        'integration_branch',
        'feature/repository.lock',
    ],
]);

/**
 * Create a project positioned at the repository wizard step.
 *
 * @return array{User, Organization, Project}
 */
function repositoryMetadataConfigurationFixture(): array
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
        'current_step' => ProjectSetupStep::Repository,
        'completed_steps' => [
            ProjectSetupStep::Details->value,
        ],
    ]);

    return [$user, $organization, $project];
}

/**
 * Return the organization-scoped route for saving repository metadata.
 */
function repositoryMetadataUpdateUrl(
    Organization $organization,
    Project $project,
): string {
    return route('organizations.projects.setup.update', [
        'organization' => $organization,
        'project' => $project,
        'step' => ProjectSetupStep::Repository,
    ]);
}
