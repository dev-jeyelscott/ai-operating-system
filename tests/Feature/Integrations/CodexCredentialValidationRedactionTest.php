<?php

declare(strict_types=1);

use App\Domain\Identity\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Create an authorized project owner for HTTP credential-boundary tests.
 *
 * @return array{0: User, 1: Organization, 2: Project}
 */
function aios243HttpCredentialFixture(): array
{
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    OrganizationMembership::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'role' => OrganizationRole::Owner,
    ]);

    $project = Project::factory()
        ->withConfiguration()
        ->create([
            'organization_id' => $organization->id,
        ]);

    return [$user, $organization, $project];
}

it('never flashes codex credential fields after validation failure', function (): void {
    [$user, $organization, $project] = aios243HttpCredentialFixture();
    $secret = 'sk-test-aios243-never-flash-this-secret-123456';

    $response = $this->actingAs($user)->post(
        route('organizations.projects.integrations.codex.test', [
            'organization' => $organization,
            'project' => $project,
        ]),
        [
            'credential' => $secret,
            'credential_confirmation' => 'different-value-123456789012345',
        ],
    );

    $response->assertSessionHasErrors('credential_confirmation');

    expect(session()->getOldInput('credential'))->toBeNull()
        ->and(session()->getOldInput('credential_confirmation'))->toBeNull();
});

it('rejects codex on the generic credential write route', function (): void {
    [$user, $organization, $project] = aios243HttpCredentialFixture();

    $response = $this->actingAs($user)->put(
        route('organizations.projects.integrations.credentials.store', [
            'organization' => $organization,
            'project' => $project,
            'provider' => 'codex',
        ]),
        [
            'credential' => 'sk-test-aios243-bypass-attempt-123456789',
        ],
    );

    $response->assertNotFound();
});
