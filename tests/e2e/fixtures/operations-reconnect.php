<?php

declare(strict_types=1);

use App\Models\Execution;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Str;

$action = trim((string) getenv('AIOS_E2E_ACTION'));

if ($action === 'prepare') {
    $suffix = strtolower((string) Str::ulid());
    $password = 'OperationsReconnect123!';

    $user = User::factory()->create([
        'name' => 'Operations Reconnect User',
        'email' => "operations-reconnect-{$suffix}@example.test",
        'email_verified_at' => now(),
        'password' => $password,
    ]);

    $organization = Organization::factory()->create([
        'name' => 'Operations Reconnect Organization',
    ]);

    OrganizationMembership::factory()
        ->owner()
        ->for($organization)
        ->for($user)
        ->create();

    $project = Project::factory()
        ->for($organization)
        ->create([
            'name' => 'Operations Reconnect Project',
        ]);

    echo json_encode([
        'email' => $user->email,
        'password' => $password,
        'projectId' => $project->id,
        'operationsUrl' => route(
            'organizations.projects.operations.index',
            [
                'organization' => $organization,
                'project' => $project,
            ],
            false,
        ),
    ], JSON_THROW_ON_ERROR).PHP_EOL;

    return;
}

if ($action === 'activate') {
    $projectId = (int) getenv('AIOS_E2E_PROJECT_ID');

    $project = Project::query()->findOrFail($projectId);

    $execution = Execution::factory()
        ->for($project)
        ->running()
        ->create([
            'capability' => 'development.simulation',
            'logical_role' => 'backend_engineer',
        ]);

    echo json_encode([
        'executionId' => $execution->id,
    ], JSON_THROW_ON_ERROR).PHP_EOL;

    return;
}

throw new RuntimeException(
    'AIOS_E2E_ACTION must be prepare or activate.',
);
