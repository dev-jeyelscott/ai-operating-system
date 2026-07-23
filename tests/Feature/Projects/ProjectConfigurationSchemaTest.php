<?php

declare(strict_types=1);

use App\Application\Projects\Contracts\ProjectRepository;
use App\Domain\Projects\Configuration\AutonomyLevel;
use App\Domain\Projects\Configuration\Exceptions\UnsupportedProjectConfigurationSchemaVersion;
use App\Domain\Projects\Configuration\ProjectConfigurationSchema;
use App\Domain\Projects\Configuration\ReasoningLevel;
use App\Domain\Projects\Configuration\RepositoryProvider;
use App\Domain\Projects\ProjectType;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectConfiguration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('creating a project initializes schema version one configuration', function (): void {
    $organization = Organization::factory()->create();

    $project = app(ProjectRepository::class)->create(
        organizationId: $organization->id,
        name: 'Versioned Configuration Project',
        description: null,
        projectType: ProjectType::WebApplication,
    );

    $configuration = $project->configuration;

    expect($configuration)
        ->toBeInstanceOf(ProjectConfiguration::class)
        ->and($configuration->schema_version)
        ->toBe(ProjectConfigurationSchema::CURRENT_VERSION)
        ->and($configuration->revision)
        ->toBe(ProjectConfigurationSchema::INITIAL_REVISION)
        ->and($configuration->integration_branch)
        ->toBe('develop')
        ->and($configuration->default_reasoning)
        ->toBe(ReasoningLevel::Medium)
        ->and($configuration->autonomy_level)
        ->toBe(AutonomyLevel::ApprovalRequired)
        ->and($configuration->notification_policy['channels'])
        ->toBe(['in_app']);

    $this->assertDatabaseHas('project_configurations', [
        'project_id' => $project->id,
        'schema_version' => ProjectConfigurationSchema::CURRENT_VERSION,
        'revision' => ProjectConfigurationSchema::INITIAL_REVISION,
        'integration_branch' => 'develop',
    ]);
});

test('configuration serializes every schema section without credentials', function (): void {
    $configuration = ProjectConfiguration::factory()
        ->complete()
        ->create();

    $payload = $configuration->toVersionedArray();

    expect($payload['schema_version'])
        ->toBe(ProjectConfigurationSchema::CURRENT_VERSION)
        ->and($payload['revision'])
        ->toBe(ProjectConfigurationSchema::INITIAL_REVISION)
        ->and($payload['technology_stack']['frameworks'])
        ->toContain('Laravel', 'React')
        ->and($payload['repository'])
        ->toMatchArray([
            'provider' => RepositoryProvider::GitHub->value,
            'default_branch' => 'main',
            'integration_branch' => 'develop',
        ])
        ->and($payload['validation_commands'])
        ->toHaveKeys([
            'build',
            'test',
            'lint',
            'static_analysis',
            'security',
        ])
        ->and($payload['policy']['default_reasoning'])
        ->toBe(ReasoningLevel::High->value)
        ->and($payload['policy']['automatic_retry_limit'])
        ->toBe(3)
        ->and($payload['policy']['autonomy_level'])
        ->toBe(AutonomyLevel::ApprovalRequired->value)
        ->and($payload['notifications']['channels'])
        ->toBe(['in_app'])
        ->and($payload)
        ->not->toHaveKey('credentials');
});

test('a project can have only one current configuration', function (): void {
    $configuration = ProjectConfiguration::factory()->create();

    expect(
        fn () => ProjectConfiguration::factory()->create([
            'project_id' => $configuration->project_id,
        ]),
    )->toThrow(QueryException::class);
});

test('deleting a project cascades its current configuration', function (): void {
    $configuration = ProjectConfiguration::factory()->create();

    $project = Project::query()->findOrFail(
        $configuration->project_id,
    );

    $project->delete();

    $this->assertDatabaseMissing('project_configurations', [
        'id' => $configuration->id,
    ]);
});

test('an unsupported persisted schema cannot be serialized', function (): void {
    $configuration = ProjectConfiguration::factory()->make([
        'schema_version' => 999,
    ]);

    expect(
        fn () => $configuration->toVersionedArray(),
    )->toThrow(UnsupportedProjectConfigurationSchemaVersion::class);
});
