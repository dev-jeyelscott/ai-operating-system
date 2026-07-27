<?php

declare(strict_types=1);

use App\Application\Projects\GetStartProjectPreflight;
use App\Domain\Audit\AuditActorType;
use App\Domain\Executions\ExecutionStatus;
use App\Domain\Integrations\IntegrationProvider;
use App\Domain\Integrations\NotionConnectionStatus;
use App\Domain\Projects\ProjectSetupStep;
use App\Domain\Projects\ProjectStatus;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Execution;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectConfiguration;
use App\Models\ProjectConfigurationVersion;
use App\Models\ProjectContextSnapshot;
use App\Models\ProjectIntegration;
use App\Models\ProjectSetupProgress;
use App\Models\ProviderCredential;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function (): void {
    /*
     * StartProject preflight must remain a local read-only query.
     */
    Http::preventStrayRequests();
});

test(
    'it reports exact start readiness without creating a context snapshot',
    function (): void {
        [
            'organization' => $organization,
            'project' => $project,
            'ciphertext' => $ciphertext,
        ] = startProjectPreflightFixture();

        $snapshotsBefore = ProjectContextSnapshot::query()
            ->where('project_id', $project->id)
            ->count();

        $result = app(GetStartProjectPreflight::class)->handle(
            organizationId: $organization->id,
            projectId: $project->id,
        );

        $snapshotsAfter = ProjectContextSnapshot::query()
            ->where('project_id', $project->id)
            ->count();

        expect($result->canStart)
            ->toBeTrue()
            ->and($result->blockers)
            ->toBe([])
            ->and($result->configuration['ready'])
            ->toBeTrue()
            ->and($result->documents['ready'])
            ->toBeTrue()
            ->and($result->documents['missing_classes'])
            ->toBe([])
            ->and($result->integration['ready'])
            ->toBeTrue()
            ->and($result->integration['credential']['configured'])
            ->toBeTrue()
            ->and($result->cost['mode'])
            ->toBe('capped')
            ->and($result->approvalGates['setup_review_confirmed'])
            ->toBeTrue()
            ->and($result->execution['active_count'])
            ->toBe(0)
            ->and($result->contextSnapshot['latest_snapshot_id'])
            ->toBeNull()
            ->and($snapshotsAfter)
            ->toBe($snapshotsBefore);

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

test(
    'it reports missing documents and a zero budget as exact blockers',
    function (): void {
        [
            'organization' => $organization,
            'project' => $project,
        ] = startProjectPreflightFixture(
            missingDocumentClass: 'architecture',
            budgetLimitMinor: 0,
        );

        $result = app(GetStartProjectPreflight::class)->handle(
            organizationId: $organization->id,
            projectId: $project->id,
        );

        $blockerKeys = collect($result->blockers)->pluck('key')->all();

        expect($result->canStart)
            ->toBeFalse()
            ->and($blockerKeys)
            ->toContain(
                'required_documents.architecture',
                'cost.budget_limit',
            )
            ->and($result->documents['missing_classes'])
            ->toBe(['architecture'])
            ->and($result->documents['ready'])
            ->toBeFalse()
            ->and($result->cost['mode'])
            ->toBe('blocked')
            ->and($result->cost['ready'])
            ->toBeFalse();

        Http::assertNothingSent();
    },
);

test(
    'it reports project status and setup review approval gates',
    function (): void {
        [
            'organization' => $organization,
            'project' => $project,
        ] = startProjectPreflightFixture(
            status: ProjectStatus::Configuring,
            setupReviewConfirmed: false,
        );

        $result = app(GetStartProjectPreflight::class)->handle(
            organizationId: $organization->id,
            projectId: $project->id,
        );

        $blockerKeys = collect($result->blockers)->pluck('key')->all();

        expect($result->canStart)
            ->toBeFalse()
            ->and($blockerKeys)
            ->toContain('project.status', 'setup.review')
            ->and($result->approvalGates['ready'])
            ->toBeFalse()
            ->and($result->approvalGates['setup_review_confirmed'])
            ->toBeFalse()
            ->and($result->approvalGates['start_requires_additional_approval'])
            ->toBeFalse();

        Http::assertNothingSent();
    },
);

test(
    'it blocks start when the current configuration has no immutable version',
    function (): void {
        [
            'organization' => $organization,
            'project' => $project,
        ] = startProjectPreflightFixture(
            recordConfigurationVersion: false,
        );

        $result = app(GetStartProjectPreflight::class)->handle(
            organizationId: $organization->id,
            projectId: $project->id,
        );

        expect($result->canStart)
            ->toBeFalse()
            ->and(collect($result->blockers)->pluck('key')->all())
            ->toContain('context.configuration_version')
            ->and($result->contextSnapshot['ready'])
            ->toBeFalse()
            ->and($result->contextSnapshot['configuration_version_id'])
            ->toBeNull();

        Http::assertNothingSent();
    },
);

test(
    'it blocks start while another project execution is active',
    function (): void {
        [
            'organization' => $organization,
            'project' => $project,
        ] = startProjectPreflightFixture();

        Execution::factory()
            ->for($project)
            ->create([
                'capability' => 'planning.roadmap',
                'status' => ExecutionStatus::Running,
            ]);

        $result = app(GetStartProjectPreflight::class)->handle(
            organizationId: $organization->id,
            projectId: $project->id,
        );

        expect($result->canStart)
            ->toBeFalse()
            ->and(collect($result->blockers)->pluck('key')->all())
            ->toContain('execution.active')
            ->and($result->execution['ready'])
            ->toBeFalse()
            ->and($result->execution['active_count'])
            ->toBe(1)
            ->and($result->execution['active'][0]['status'])
            ->toBe(ExecutionStatus::Running->value);

        Http::assertNothingSent();
    },
);

test(
    'it enforces organization isolation',
    function (): void {
        [
            'project' => $project,
        ] = startProjectPreflightFixture();

        $otherOrganization = Organization::factory()->create();

        expect(
            fn () => app(GetStartProjectPreflight::class)->handle(
                organizationId: $otherOrganization->id,
                projectId: $project->id,
            ),
        )->toThrow(ModelNotFoundException::class);

        Http::assertNothingSent();
    },
);

/**
 * Create one fully configured StartProject preflight fixture.
 *
 * @return array{
 *     organization: Organization,
 *     project: Project,
 *     configuration: ProjectConfiguration,
 *     credential: ProviderCredential,
 *     integration: ProjectIntegration,
 *     ciphertext: string
 * }
 */
function startProjectPreflightFixture(
    ?string $missingDocumentClass = null,
    ?int $budgetLimitMinor = 5000,
    ProjectStatus $status = ProjectStatus::ReadyForPlanning,
    bool $setupReviewConfirmed = true,
    bool $recordConfigurationVersion = true,
): array {
    $organization = Organization::factory()->create();

    $project = Project::factory()
        ->for($organization)
        ->create([
            'status' => $status,
            'status_changed_at' => now(),
        ]);

    $configuration = ProjectConfiguration::factory()
        ->complete()
        ->for($project)
        ->create([
            'budget_limit_minor' => $budgetLimitMinor,
        ]);

    if ($recordConfigurationVersion) {
        ProjectConfigurationVersion::query()->create([
            'project_id' => $project->id,
            'schema_version' => $configuration->schema_version,
            'revision' => $configuration->revision,
            'actor_type' => AuditActorType::System,
            'actor_id' => 'start-project-preflight-test',
            'change_reason' => 'test_fixture',
            'snapshot' => $configuration->toVersionedArray(),
            'created_at' => now(),
        ]);
    }

    foreach ($configuration->required_documents as $documentClass) {
        if ($documentClass === $missingDocumentClass) {
            continue;
        }

        $document = Document::factory()
            ->for($project)
            ->create([
                'document_class' => $documentClass,
                'title' => Str::headline($documentClass),
            ]);

        DocumentVersion::factory()
            ->for($document)
            ->approved()
            ->create();
    }

    ProjectSetupProgress::query()->create([
        'project_id' => $project->id,
        'current_step' => ProjectSetupStep::Review,
        'completed_steps' => $setupReviewConfirmed
            ? array_map(
                static fn (ProjectSetupStep $step): string => $step->value,
                ProjectSetupStep::ordered(),
            )
            : array_map(
                static fn (ProjectSetupStep $step): string => $step->value,
                array_filter(
                    ProjectSetupStep::ordered(),
                    static fn (ProjectSetupStep $step): bool => $step !== ProjectSetupStep::Review,
                ),
            ),
        'completed_at' => $setupReviewConfirmed ? now() : null,
    ]);

    $ciphertext = 'encrypted-start-project-preflight-fixture';

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
        'data_source_id' => (string) Str::uuid(),
        'data_source_name' => 'AIOS Delivery Tracker Data Source',
        'connection_status' => NotionConnectionStatus::Connected,
        'last_failure_code' => null,
        'last_provider_request_id' => 'request-aios-062',
        'last_tested_by_user_id' => null,
        'last_tested_at' => now()->addSecond(),
        'last_connected_at' => now()->addSecond(),
        'verified_credential_version' => $credential->version,
    ]);

    return [
        'organization' => $organization,
        'project' => $project,
        'configuration' => $configuration,
        'credential' => $credential,
        'integration' => $integration,
        'ciphertext' => $ciphertext,
    ];
}
