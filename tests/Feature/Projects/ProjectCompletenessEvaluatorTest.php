<?php

declare(strict_types=1);

use App\Application\Projects\EvaluateProjectCompleteness;
use App\Domain\Integrations\IntegrationProvider;
use App\Domain\Integrations\NotionConnectionStatus;
use App\Domain\Projects\ProjectSetupStep;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectConfiguration;
use App\Models\ProjectIntegration;
use App\Models\ProjectSetupProgress;
use App\Models\ProviderCredential;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function (): void {
    /*
     * Completeness evaluation must remain a local read-only operation.
     */
    Http::preventStrayRequests();
});

test(
    'it returns exact missing configuration and remediation steps',
    function (): void {
        $organization = Organization::factory()->create();

        $project = Project::factory()
            ->for($organization)
            ->create();

        ProjectConfiguration::factory()
            ->for($project)
            ->create();

        $result = app(EvaluateProjectCompleteness::class)->handle(
            organizationId: $organization->id,
            projectId: $project->id,
        );

        expect($result->isComplete())
            ->toBeFalse()
            ->and($result->missingKeys())
            ->toBe([
                'technology_stack.languages',
                'repository.provider',
                'repository.url',
                'repository.default_branch',
                'integrations.notion.credential',
                'integrations.notion.connection',
                'validation_commands.build',
                'validation_commands.test',
                'validation_commands.lint',
                'validation_commands.static_analysis',
                'validation_commands.security',
                'required_documents',
                'policy.provider.allowed_provider_ids',
                'policy.provider.fallback_order',
                'setup.review',
            ]);

        foreach ($result->issues as $issue) {
            expect($issue->message)
                ->not->toBeEmpty()
                ->and($issue->remediation)
                ->not->toBeEmpty();
        }

        Http::assertNothingSent();
    },
);

test(
    'it reports a fully configured project as complete',
    function (): void {
        [
            'organization' => $organization,
            'project' => $project,
        ] = completeProjectCompletenessFixture();

        $result = app(EvaluateProjectCompleteness::class)->handle(
            organizationId: $organization->id,
            projectId: $project->id,
        );

        expect($result->isComplete())
            ->toBeTrue()
            ->and($result->issues)
            ->toBe([])
            ->and($result->toArray())
            ->toBe([
                'project_id' => $project->id,
                'complete' => true,
                'missing_configuration' => [],
            ]);

        Http::assertNothingSent();
    },
);

test(
    'it requires a new connection test after credential rotation',
    function (): void {
        [
            'organization' => $organization,
            'project' => $project,
            'credential' => $credential,
            'integration' => $integration,
        ] = completeProjectCompletenessFixture();

        /*
         * Simulate a credential rotation after the successful test.
         */
        ProviderCredential::withoutTimestamps(
            static function () use ($credential): void {
                $credential->forceFill([
                    'version' => $credential->version + 1,
                    'updated_at' => now()->addMinute(),
                    'rotated_at' => now()->addMinute(),
                ])->save();
            },
        );

        expect($integration->connection_status)
            ->toBe(NotionConnectionStatus::Connected);

        $result = app(EvaluateProjectCompleteness::class)->handle(
            organizationId: $organization->id,
            projectId: $project->id,
        );

        expect($result->isComplete())
            ->toBeFalse()
            ->and($result->missingKeys())
            ->toContain('integrations.notion.connection')
            ->and(
                collect($result->issues)
                    ->firstWhere(
                        'key',
                        'integrations.notion.connection',
                    )
                    ?->remediation,
            )
            ->toContain('run the Notion connection test again');

        Http::assertNothingSent();
    },
);

test(
    'it enforces organization isolation',
    function (): void {
        $owningOrganization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();

        $project = Project::factory()
            ->for($owningOrganization)
            ->create();

        ProjectConfiguration::factory()
            ->complete()
            ->for($project)
            ->create();

        expect(
            fn () => app(EvaluateProjectCompleteness::class)
                ->handle(
                    organizationId: $otherOrganization->id,
                    projectId: $project->id,
                ),
        )->toThrow(ModelNotFoundException::class);

        Http::assertNothingSent();
    },
);

test(
    'it never exposes encrypted credential material',
    function (): void {
        [
            'organization' => $organization,
            'project' => $project,
            'ciphertext' => $ciphertext,
        ] = completeProjectCompletenessFixture();

        $result = app(EvaluateProjectCompleteness::class)->handle(
            organizationId: $organization->id,
            projectId: $project->id,
        );

        $serialized = json_encode(
            $result->toArray(),
            JSON_THROW_ON_ERROR,
        );

        expect($serialized)
            ->not->toContain($ciphertext)
            ->not->toContain('secret_ciphertext');

        Http::assertNothingSent();
    },
);

/**
 * Create a complete project configuration with verified Notion metadata.
 *
 * @return array{
 *     organization: Organization,
 *     project: Project,
 *     credential: ProviderCredential,
 *     integration: ProjectIntegration,
 *     ciphertext: string
 * }
 */
function completeProjectCompletenessFixture(): array
{
    $organization = Organization::factory()->create();

    $project = Project::factory()
        ->for($organization)
        ->create();

    ProjectConfiguration::factory()
        ->complete()
        ->for($project)
        ->create();

    ProjectSetupProgress::query()->create([
        'project_id' => $project->id,
        'current_step' => ProjectSetupStep::Review,
        'completed_steps' => array_map(
            static fn (
                ProjectSetupStep $step,
            ): string => $step->value,
            ProjectSetupStep::ordered(),
        ),
        'completed_at' => now(),
    ]);

    $ciphertext = 'encrypted-notion-credential-fixture';

    $credential = ProviderCredential::query()->create([
        'organization_id' => $organization->id,
        'project_id' => $project->id,
        'provider' => IntegrationProvider::Notion,
        'secret_ciphertext' => $ciphertext,
        'version' => 1,
        'created_by_user_id' => null,
        'last_rotated_by_user_id' => null,
        'rotated_at' => null,
    ]);

    $integration = ProjectIntegration::query()->create([
        'organization_id' => $organization->id,
        'project_id' => $project->id,
        'provider' => IntegrationProvider::Notion,
        'workspace_id' => (string) Str::uuid(),
        'workspace_name' => 'AIOS Test Workspace',
        'database_id' => (string) Str::uuid(),
        'database_name' => 'AIOS Delivery Tracker',
        'connection_status' => NotionConnectionStatus::Connected,
        'last_failure_code' => null,
        'last_provider_request_id' => 'request-aios-029',
        'last_tested_by_user_id' => null,
        'last_tested_at' => now()->addSecond(),
        'last_connected_at' => now()->addSecond(),
    ]);

    return [
        'organization' => $organization,
        'project' => $project,
        'credential' => $credential,
        'integration' => $integration,
        'ciphertext' => $ciphertext,
    ];
}
