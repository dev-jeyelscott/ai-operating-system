<?php

declare(strict_types=1);

use App\Application\Projects\EvaluateProjectCompleteness;
use App\Domain\Integrations\IntegrationProvider;
use App\Domain\Projects\Configuration\CodexProviderPolicy;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectConfiguration;
use App\Models\ProviderCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;

uses(RefreshDatabase::class);

it('blocks project completeness when codex is enabled without a credential', function (): void {
    $organization = Organization::factory()->create();
    $project = Project::factory()->create([
        'organization_id' => $organization->id,
    ]);
    $configuration = ProjectConfiguration::factory()
        ->complete()
        ->create([
            'project_id' => $project->id,
        ]);

    $codex = CodexProviderPolicy::defaults();
    $codex['enabled'] = true;

    $configuration->forceFill([
        'provider_policy' => [
            'allowed_provider_ids' => ['codex', 'simulation'],
            'fallback_order' => ['simulation'],
            'codex' => $codex,
        ],
    ])->save();

    $result = app(EvaluateProjectCompleteness::class)->handle(
        organizationId: $organization->id,
        projectId: $project->id,
    );

    expect($result->missingKeys())
        ->toContain('integrations.codex.credential');
});

it('blocks codex when the stored credential version is not currently verified', function (): void {
    $organization = Organization::factory()->create();
    $project = Project::factory()->create([
        'organization_id' => $organization->id,
    ]);
    $configuration = ProjectConfiguration::factory()
        ->complete()
        ->create([
            'project_id' => $project->id,
        ]);

    $codex = CodexProviderPolicy::defaults();
    $codex['enabled'] = true;

    $configuration->forceFill([
        'provider_policy' => [
            'allowed_provider_ids' => ['codex', 'simulation'],
            'fallback_order' => ['simulation'],
            'codex' => $codex,
        ],
    ])->save();

    ProviderCredential::query()->create([
        'organization_id' => $organization->id,
        'project_id' => $project->id,
        'provider' => IntegrationProvider::Codex,
        'secret_ciphertext' => Crypt::encryptString(
            'sk-test-aios243-completeness-123456789',
        ),
        'version' => 2,
        'verified_credential_version' => 1,
        'last_connection_status' => 'connected',
        'last_connected_at' => now(),
    ]);

    $result = app(EvaluateProjectCompleteness::class)->handle(
        organizationId: $organization->id,
        projectId: $project->id,
    );

    expect($result->missingKeys())
        ->toContain('integrations.codex.connection');
});
