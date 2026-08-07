<?php

declare(strict_types=1);

use App\Application\Projects\UpdateProjectCodexProviderPolicy;
use App\Domain\Identity\OrganizationRole;
use App\Domain\Integrations\IntegrationProvider;
use App\Domain\Projects\Configuration\CodexProviderPolicy;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\ProjectConfiguration;
use App\Models\ProviderCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;

uses(RefreshDatabase::class);

it('invalidates codex preflight after a material provider policy revision', function (): void {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    OrganizationMembership::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'role' => OrganizationRole::Owner,
    ]);

    $project = Project::factory()->create([
        'organization_id' => $organization->id,
    ]);

    $codex = CodexProviderPolicy::defaults();
    $codex['enabled'] = true;

    ProjectConfiguration::factory()
        ->complete()
        ->create([
            'project_id' => $project->id,
            'provider_policy' => [
                'allowed_provider_ids' => ['codex', 'simulation'],
                'fallback_order' => ['simulation', 'codex'],
                'codex' => $codex,
            ],
        ]);

    $credential = ProviderCredential::query()->create([
        'organization_id' => $organization->id,
        'project_id' => $project->id,
        'provider' => IntegrationProvider::Codex,
        'secret_ciphertext' => Crypt::encryptString(
            'sk-test-aios243-policy-invalidation-123456789',
        ),
        'version' => 1,
        'last_connection_status' => 'connected',
        'verified_credential_version' => 1,
        'last_tested_at' => now(),
        'last_connected_at' => now(),
    ]);

    $updatedCodex = $codex;
    $updatedCodex['model_identifier'] = 'gpt-5.3-codex-project-policy-test';

    app(UpdateProjectCodexProviderPolicy::class)->handle(
        actorUserId: $user->id,
        organizationId: $organization->id,
        projectId: $project->id,
        codexPolicyPayload: $updatedCodex,
        fallbackEnabled: true,
        correlationId: 'aios243-policy-invalidation',
    );

    $credential->refresh();

    expect($credential->last_connection_status)->toBeNull()
        ->and($credential->verified_credential_version)->toBeNull()
        ->and($credential->last_tested_at)->toBeNull()
        ->and($credential->last_connected_at)->not->toBeNull();
});
