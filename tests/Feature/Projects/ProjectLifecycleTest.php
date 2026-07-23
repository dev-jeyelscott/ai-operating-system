<?php

declare(strict_types=1);

use App\Domain\Projects\Exceptions\InvalidProjectStatusTransition;
use App\Domain\Projects\ProjectStatus;
use App\Domain\Projects\ProjectType;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('a project is persisted as a draft with domain enum casts', function () {
    $project = Project::factory()->create();

    expect($project->project_type)
        ->toBe(ProjectType::WebApplication)
        ->and($project->status)
        ->toBe(ProjectStatus::Draft)
        ->and($project->status_changed_at)
        ->not->toBeNull()
        ->and($project->organization)
        ->toBeInstanceOf(Organization::class);
});

test('an organization owns its projects', function () {
    $organization = Organization::factory()->create();

    $project = Project::factory()
        ->for($organization)
        ->create();

    expect(
        $organization
            ->projects()
            ->whereKey($project->id)
            ->exists(),
    )->toBeTrue();
});

test('a valid project transition is persisted through the aggregate', function () {
    $project = Project::factory()->create();

    $project->transitionTo(ProjectStatus::Configuring);

    expect($project->status)->toBe(ProjectStatus::Configuring);

    $this->assertDatabaseHas('projects', [
        'id' => $project->id,
        'status' => ProjectStatus::Configuring->value,
    ]);
});

test('an invalid project transition leaves persistence unchanged', function () {
    $project = Project::factory()->create();

    expect(
        fn () => $project->transitionTo(ProjectStatus::Active),
    )->toThrow(InvalidProjectStatusTransition::class);

    expect($project->refresh()->status)
        ->toBe(ProjectStatus::Draft);
});

test('organization ownership and project status are not mass assignable', function () {
    $project = new Project;

    expect($project->isFillable('name'))
        ->toBeTrue()
        ->and($project->isFillable('project_type'))
        ->toBeTrue()
        ->and($project->isFillable('organization_id'))
        ->toBeFalse()
        ->and($project->isFillable('status'))
        ->toBeFalse();
});

test('project slugs are globally unique stable identifiers', function () {
    Project::factory()->create([
        'slug' => 'shared-project',
    ]);

    expect(
        fn () => Project::factory()->create([
            'slug' => 'shared-project',
        ]),
    )->toThrow(QueryException::class);
});

test('the database rejects unknown project status values', function () {
    $organization = Organization::factory()->create();

    expect(
        fn () => DB::table('projects')->insert([
            'organization_id' => $organization->id,
            'name' => 'Invalid State Project',
            'slug' => 'invalid-state-project',
            'project_type' => ProjectType::WebApplication->value,
            'status' => 'unknown',
            'status_changed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]),
    )->toThrow(QueryException::class);
});

test('the database rejects unknown project type values', function () {
    $organization = Organization::factory()->create();

    expect(
        fn () => DB::table('projects')->insert([
            'organization_id' => $organization->id,
            'name' => 'Invalid Type Project',
            'slug' => 'invalid-type-project',
            'project_type' => 'desktop_metaverse',
            'status' => ProjectStatus::Draft->value,
            'status_changed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]),
    )->toThrow(QueryException::class);
});
