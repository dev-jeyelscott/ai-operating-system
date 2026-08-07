<?php

declare(strict_types=1);

use App\Domain\Integrations\IntegrationProvider;
use App\Domain\Integrations\NotionConnectionStatus;
use App\Domain\Projects\Configuration\ProjectConfigurationSchema;
use App\Domain\Projects\ProjectSetupStep;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Project;
use App\Models\ProjectConfiguration;
use App\Models\ProjectIntegration;
use App\Models\ProjectSetupProgress;
use App\Models\ProviderCredential;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    /*
     * These screens must remain local, read-only projections.
     */
    Http::preventStrayRequests();
});

test(
    'an authorized user can view persisted project settings',
    function (): void {
        [
            'user' => $user,
            'organization' => $organization,
            'project' => $project,
        ] = projectConfigurationScreensFixture();

        $this->actingAs($user)
            ->get(route('organizations.projects.settings.show', [
                'organization' => $organization,
                'project' => $project,
            ]))
            ->assertOk()
            ->assertInertia(
                fn(Assert $page): Assert => $page
                    ->component('projects/settings')
                    ->where('project.id', $project->id)
                    ->where(
                        'configuration.schemaVersion',
                        ProjectConfigurationSchema::CURRENT_VERSION,
                    )
                    ->where(
                        'configuration.repository.integrationBranch',
                        'develop',
                    )
                    ->where('validation.complete', true)
                    ->where('permissions.update', true)
                    ->has('urls.setupSteps.integrations'),
            );

        Http::assertNothingSent();
    },
);

test(
    'the integrations screen exposes safe metadata only',
    function (): void {
        [
            'user' => $user,
            'organization' => $organization,
            'project' => $project,
            'ciphertext' => $ciphertext,
        ] = projectConfigurationScreensFixture();

        $response = $this->actingAs($user)
            ->get(route('organizations.projects.integrations.index', [
                'organization' => $organization,
                'project' => $project,
            ]));

        $response
            ->assertOk()
            ->assertInertia(
                fn(Assert $page): Assert => $page
                    ->component('projects/integrations')
                    ->where('integration.provider', 'notion')
                    ->where('integration.status', 'connected')
                    ->where(
                        'integration.workspaceName',
                        'AIOS Test Workspace',
                    )
                    ->where(
                        'integration.databaseName',
                        'AIOS Delivery Tracker',
                    )
                    ->where(
                        'integration.credential.configured',
                        true,
                    )
                    ->where('integration.credential.version', 1)
                    ->where(
                        'permissions.manageIntegrations',
                        true,
                    )
                    ->missing('integration.credential.secret')
                    ->missing(
                        'integration.credential.secretCiphertext',
                    ),
            )
            ->assertDontSee($ciphertext, false)
            ->assertDontSee('secret_ciphertext', false);

        Http::assertNothingSent();
    },
);

test(
    'incomplete projects show exact remediation metadata',
    function (): void {
        [
            'user' => $user,
            'organization' => $organization,
            'project' => $project,
        ] = projectConfigurationScreensFixture(complete: false);

        $this->actingAs($user)
            ->get(route('organizations.projects.settings.show', [
                'organization' => $organization,
                'project' => $project,
            ]))
            ->assertOk()
            ->assertInertia(
                fn(Assert $page): Assert => $page
                    ->component('projects/settings')
                    ->where('validation.complete', false)
                    ->where(
                        'validation.missingConfiguration.0.key',
                        'technology_stack.languages',
                    )
                    ->where(
                        'validation.missingConfiguration.0.step',
                        'details',
                    )
                    ->where(
                        'validation.missingConfiguration.0.message',
                        'At least one project language is required.',
                    )
                    ->has(
                        'validation.missingConfiguration.0.remediation',
                    ),
            );

        Http::assertNothingSent();
    },
);

test(
    'viewers can inspect integrations but cannot manage them',
    function (): void {
        [
            'organization' => $organization,
            'project' => $project,
        ] = projectConfigurationScreensFixture();

        $viewer = User::factory()->create();

        OrganizationMembership::factory()
            ->for($organization)
            ->for($viewer)
            ->viewer()
            ->create();

        $this->actingAs($viewer)
            ->get(route('organizations.projects.integrations.index', [
                'organization' => $organization,
                'project' => $project,
            ]))
            ->assertOk()
            ->assertInertia(
                fn(Assert $page): Assert => $page
                    ->where(
                        'permissions.manageIntegrations',
                        false,
                    ),
            );

        Http::assertNothingSent();
    },
);

test(
    'a project cannot be viewed through another organization',
    function (): void {
        [
            'project' => $project,
        ] = projectConfigurationScreensFixture();

        $otherUser = User::factory()->create();
        $otherOrganization = Organization::factory()->create();

        OrganizationMembership::factory()
            ->for($otherOrganization)
            ->for($otherUser)
            ->owner()
            ->create();

        $this->actingAs($otherUser)
            ->get(route('organizations.projects.settings.show', [
                'organization' => $otherOrganization,
                'project' => $project,
            ]))
            ->assertNotFound();

        Http::assertNothingSent();
    },
);

/**
 * Create a complete or intentionally incomplete project screen fixture.
 *
 * @return array{
 *     user: User,
 *     organization: Organization,
 *     project: Project,
 *     ciphertext: string|null
 * }
 */
function projectConfigurationScreensFixture(
    bool $complete = true,
): array {
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

    if (! $complete) {
        ProjectConfiguration::factory()
            ->for($project)
            ->create();

        ProjectSetupProgress::query()->create([
            'project_id' => $project->id,
            'current_step' => ProjectSetupStep::Details,
            'completed_steps' => [],
            'completed_at' => null,
        ]);

        return [
            'user' => $user,
            'organization' => $organization,
            'project' => $project,
            'ciphertext' => null,
        ];
    }

    ProjectConfiguration::factory()
        ->complete()
        ->for($project)
        ->create();

    foreach (['product_charter', 'requirements', 'architecture'] as $documentClass) {
        $document = Document::factory()
            ->for($project)
            ->create(['document_class' => $documentClass]);

        DocumentVersion::factory()
            ->for($document)
            ->approved()
            ->create();
    }

    ProjectSetupProgress::query()->create([
        'project_id' => $project->id,
        'current_step' => ProjectSetupStep::Review,
        'completed_steps' => array_map(
            static fn(
                ProjectSetupStep $step,
            ): string => $step->value,
            ProjectSetupStep::ordered(),
        ),
        'completed_at' => now(),
    ]);

    $ciphertext = 'encrypted-aios-030-test-credential';

    ProviderCredential::query()->create([
        'organization_id' => $organization->id,
        'project_id' => $project->id,
        'provider' => IntegrationProvider::Notion,
        'secret_ciphertext' => $ciphertext,
        'version' => 1,
        'created_by_user_id' => $user->id,
        'last_rotated_by_user_id' => null,
        'rotated_at' => null,
    ]);

    ProjectIntegration::query()->create([
        'organization_id' => $organization->id,
        'project_id' => $project->id,
        'provider' => IntegrationProvider::Notion,
        'workspace_id' => (string) Str::uuid(),
        'workspace_name' => 'AIOS Test Workspace',
        'database_id' => (string) Str::uuid(),
        'database_name' => 'AIOS Delivery Tracker',
        'data_source_id' => (string) Str::uuid(),
        'data_source_name' => 'AIOS Delivery Tracker Data Source',
        'connection_status' => NotionConnectionStatus::Connected,
        'last_failure_code' => null,
        'last_provider_request_id' => 'request-aios-030',
        'last_tested_by_user_id' => $user->id,
        'last_tested_at' => now()->addSecond(),
        'last_connected_at' => now()->addSecond(),
    ]);

    return [
        'user' => $user,
        'organization' => $organization,
        'project' => $project,
        'ciphertext' => $ciphertext,
    ];
}
