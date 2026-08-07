<?php

declare(strict_types=1);

use App\Models\Execution;
use App\Models\ExecutionAttempt;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * Create an owner and project for usage-view tests.
 *
 * @return array{0: User, 1: Organization, 2: Project}
 */
function createUsageViewContext(): array
{
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    OrganizationMembership::factory()
        ->owner()
        ->for($organization)
        ->for($user)
        ->create();

    $project = Project::factory()
        ->for($organization)
        ->create();

    return [$user, $organization, $project];
}

it('separates estimated simulation and actual provider cost', function (): void {
    [$user, $organization, $project] = createUsageViewContext();

    $simulationExecution = Execution::factory()
        ->for($project)
        ->completed()
        ->create([
            'logical_role' => 'backend_engineer',
        ]);

    ExecutionAttempt::factory()
        ->for($simulationExecution)
        ->completed()
        ->create([
            'execution_provider' => 'simulation',
            'estimated_cost' => '0.25000000',
            'actual_cost' => null,
            'cost_currency' => 'USD',
        ]);

    $providerExecution = Execution::factory()
        ->for($project)
        ->completed()
        ->create([
            'logical_role' => 'qa_engineer',
        ]);

    ExecutionAttempt::factory()
        ->for($providerExecution)
        ->completed()
        ->create([
            'execution_provider' => 'openai',
            'simulation_mode' => null,
            'simulation_seed' => null,
            'estimated_cost' => '0.30000000',
            'actual_cost' => '0.28000000',
            'cost_currency' => 'USD',
        ]);

    $response = $this
        ->actingAs($user)
        ->get(route(
            'organizations.projects.operations.usage.index',
            [
                'organization' => $organization,
                'project' => $project,
            ],
        ));

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('projects/operations/usage')
            ->where('usage.summary.attempts', 2)
            ->where('usage.summary.executions', 2)
            ->has('usage.summary.currencies', 1)
            ->where(
                'usage.summary.currencies.0.estimatedSimulationCost',
                '0.25000000',
            )
            ->where(
                'usage.summary.currencies.0.estimatedProviderCost',
                '0.30000000',
            )
            ->where(
                'usage.summary.currencies.0.actualProviderCost',
                '0.28000000',
            )
            ->where(
                'usage.dataQuality.simulationActualCostRecords',
                0,
            )
        );
});

it('excludes attempts owned by another project', function (): void {
    [$user, $organization, $project] = createUsageViewContext();

    $otherProject = Project::factory()
        ->for($organization)
        ->create();

    $execution = Execution::factory()
        ->for($otherProject)
        ->completed()
        ->create();

    ExecutionAttempt::factory()
        ->for($execution)
        ->completed()
        ->create([
            'estimated_cost' => '100.00000000',
            'cost_currency' => 'USD',
        ]);

    $this
        ->actingAs($user)
        ->get(route(
            'organizations.projects.operations.usage.index',
            [
                'organization' => $organization,
                'project' => $project,
            ],
        ))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('usage.summary.attempts', 0)
            ->where('usage.summary.executions', 0)
            ->has('usage.summary.currencies', 0)
        );
});
