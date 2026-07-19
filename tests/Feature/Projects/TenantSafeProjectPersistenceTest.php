<?php

declare(strict_types=1);

use App\Application\Projects\Contracts\ProjectRepository;
use App\Domain\Projects\ProjectStatus;
use App\Domain\Projects\ProjectType;
use App\Infrastructure\Persistence\Repositories\Projects\EloquentProjectRepository;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Database\Eloquent\ModelNotFoundException;

test('the project repository contract resolves to the eloquent adapter', function () {
    expect(app(ProjectRepository::class))
        ->toBeInstanceOf(EloquentProjectRepository::class);
});

test('the project organization scope excludes every other tenant', function () {
    $firstOrganization = Organization::factory()->create();
    $secondOrganization = Organization::factory()->create();

    $firstProject = Project::factory()
        ->for($firstOrganization)
        ->create();

    $secondProject = Project::factory()
        ->for($secondOrganization)
        ->create();

    $projectIds = Project::query()
        ->forOrganization($firstOrganization->id)
        ->pluck('id')
        ->all();

    expect($projectIds)
        ->toContain($firstProject->id)
        ->not->toContain($secondProject->id);
});

test('tenant scoped repository reads do not resolve a foreign project id', function () {
    $firstOrganization = Organization::factory()->create();
    $secondOrganization = Organization::factory()->create();

    $foreignProject = Project::factory()
        ->for($secondOrganization)
        ->create();

    $projects = app(ProjectRepository::class);

    expect(
        fn () => $projects->findByIdOrFail(
            organizationId: $firstOrganization->id,
            projectId: $foreignProject->id,
        ),
    )->toThrow(ModelNotFoundException::class);
});

test('tenant scoped repository lists never include foreign projects', function () {
    $firstOrganization = Organization::factory()->create();
    $secondOrganization = Organization::factory()->create();

    $visibleProject = Project::factory()
        ->for($firstOrganization)
        ->create();

    $foreignProject = Project::factory()
        ->for($secondOrganization)
        ->create();

    $projects = app(ProjectRepository::class)
        ->paginateForOrganization(
            organizationId: $firstOrganization->id,
            archived: false,
            perPage: 100,
        );

    $projectIds = collect($projects->items())
        ->map(static fn (Project $project): int => $project->id)
        ->all();

    expect($projectIds)
        ->toContain($visibleProject->id)
        ->not->toContain($foreignProject->id);
});

test('tenant scoped repository writes cannot mutate foreign projects', function () {
    $firstOrganization = Organization::factory()->create();
    $secondOrganization = Organization::factory()->create();

    $foreignProject = Project::factory()
        ->for($secondOrganization)
        ->create([
            'name' => 'Foreign Project',
            'project_type' => ProjectType::WebApplication,
            'status' => ProjectStatus::Draft,
        ]);

    $foreignArchivedProject = Project::factory()
        ->for($secondOrganization)
        ->create([
            'archived_at' => now(),
        ]);

    $projects = app(ProjectRepository::class);

    expect(
        fn () => $projects->update(
            organizationId: $firstOrganization->id,
            projectId: $foreignProject->id,
            name: 'Leaked Update',
            description: 'This must never persist.',
            projectType: ProjectType::Api,
        ),
    )->toThrow(ModelNotFoundException::class);

    expect(
        fn () => $projects->archive(
            organizationId: $firstOrganization->id,
            projectId: $foreignProject->id,
        ),
    )->toThrow(ModelNotFoundException::class);

    expect(
        fn () => $projects->restore(
            organizationId: $firstOrganization->id,
            projectId: $foreignArchivedProject->id,
        ),
    )->toThrow(ModelNotFoundException::class);

    expect(
        fn () => $projects->transitionTo(
            organizationId: $firstOrganization->id,
            projectId: $foreignProject->id,
            target: ProjectStatus::Configuring,
        ),
    )->toThrow(ModelNotFoundException::class);

    expect($foreignProject->refresh()->name)
        ->toBe('Foreign Project')
        ->and($foreignProject->project_type)
        ->toBe(ProjectType::WebApplication)
        ->and($foreignProject->status)
        ->toBe(ProjectStatus::Draft)
        ->and($foreignProject->archived_at)
        ->toBeNull()
        ->and($foreignArchivedProject->refresh()->archived_at)
        ->not->toBeNull();
});

test('project repository mutations report factual persistence changes', function () {
    $organization = Organization::factory()->create();

    $project = Project::factory()
        ->for($organization)
        ->create([
            'name' => 'Stable Project',
            'description' => null,
            'project_type' => ProjectType::Api,
        ]);

    $projects = app(ProjectRepository::class);

    $unchangedUpdate = $projects->update(
        organizationId: $organization->id,
        projectId: $project->id,
        name: 'Stable Project',
        description: null,
        projectType: ProjectType::Api,
    );

    $changedUpdate = $projects->update(
        organizationId: $organization->id,
        projectId: $project->id,
        name: 'Changed Project',
        description: null,
        projectType: ProjectType::Api,
    );

    $firstArchive = $projects->archive(
        organizationId: $organization->id,
        projectId: $project->id,
    );

    $secondArchive = $projects->archive(
        organizationId: $organization->id,
        projectId: $project->id,
    );

    $firstRestore = $projects->restore(
        organizationId: $organization->id,
        projectId: $project->id,
    );

    $secondRestore = $projects->restore(
        organizationId: $organization->id,
        projectId: $project->id,
    );

    expect($unchangedUpdate->changed)
        ->toBeFalse()
        ->and($changedUpdate->changed)
        ->toBeTrue()
        ->and($firstArchive->changed)
        ->toBeTrue()
        ->and($secondArchive->changed)
        ->toBeFalse()
        ->and($firstRestore->changed)
        ->toBeTrue()
        ->and($secondRestore->changed)
        ->toBeFalse();
});
