<?php

declare(strict_types=1);

use App\Application\Integrations\Contracts\NotionConnectionGateway;
use App\Application\Integrations\NotionConnectionTestResult;
use App\Application\Integrations\TestProjectNotionConnection;
use App\Application\Projects\CreateProject;
use App\Domain\Integrations\NotionConnectionFailureCode;
use App\Domain\Projects\ProjectSetupStep;
use App\Domain\Projects\ProjectType;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ProjectIntegration;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->organization = Organization::factory()->create();

    OrganizationMembership::factory()
        ->for($this->organization)
        ->for($this->user)
        ->owner()
        ->create();

    $this->project = app(CreateProject::class)->handle(
        actorUserId: $this->user->id,
        organizationId: $this->organization->id,
        name: 'AIOS-174 Snapshot Project',
        description: 'Exercises canonical Notion snapshot history.',
        projectType: ProjectType::WebApplication,
        correlationId: 'aios-174-project-created',
    );

    /*
     * Position the fixture at the Integrations step without creating unrelated
     * project-configuration revisions.
     */
    $progress = $this->project->setupProgress()->firstOrFail();

    $progress->forceFill([
        'current_step' => ProjectSetupStep::Integrations,
        'completed_steps' => [
            ProjectSetupStep::Details->value,
            ProjectSetupStep::Repository->value,
        ],
    ])->save();
});

test('the baseline explicitly represents an unconfigured Notion integration', function (): void {
    $version = $this->project
        ->configurationVersions()
        ->where('revision', 1)
        ->sole();

    expect(data_get(
        $version->snapshot,
        'integrations.notion.provider',
    ))
        ->toBe('notion')
        ->and(data_get(
            $version->snapshot,
            'integrations.notion.connection_status',
        ))
        ->toBe('unconfigured')
        ->and(data_get(
            $version->snapshot,
            'integrations.notion.workspace.id',
        ))
        ->toBeNull()
        ->and(data_get(
            $version->snapshot,
            'integrations.notion.database.id',
        ))
        ->toBeNull()
        ->and(data_get(
            $version->snapshot,
            'integrations.notion.credential.configured',
        ))
        ->toBeFalse()
        ->and(data_get(
            $version->snapshot,
            'integrations.notion.credential.verified_version',
        ))
        ->toBeNull();
});

test('a first successful connection records target and credential provenance without secrets', function (): void {
    $credential =
        'secret_notion_aios_174_abcdefghijklmnopqrstuvwxyz';

    $workspaceId =
        '17ab3186-873d-418f-b899-c3f6a43f68de';

    $databaseId =
        'd9824bdc-8445-4327-be8b-5b47500af6ce';

    $dataSourceId =
        '248104cd-477e-80af-bc30-000bd28de8f9';

    $this->mock(
        NotionConnectionGateway::class,
        function (MockInterface $mock) use (
            $workspaceId,
            $databaseId,
            $dataSourceId,
        ): void {
            $mock->shouldReceive('test')
                ->once()
                ->andReturn(NotionConnectionTestResult::connected(
                    workspaceId: $workspaceId,
                    workspaceName: 'AI Operating System',
                    databaseId: $databaseId,
                    databaseName: 'AIOS Tickets',
                    dataSourceId: $dataSourceId,
                    dataSourceName: 'Tickets',
                    providerRequestId: 'req-aios-174-first',
                ));
        },
    );

    app(TestProjectNotionConnection::class)->handle(
        actorUserId: $this->user->id,
        organizationId: $this->organization->id,
        projectId: $this->project->id,
        databaseReference: $databaseId,
        plaintextCredential: $credential,
        correlationId: 'aios-174-first-connection',
    );

    $version = $this->project
        ->configurationVersions()
        ->where('revision', 2)
        ->sole();

    expect(data_get(
        $version->snapshot,
        'integrations.notion.connection_status',
    ))
        ->toBe('connected')
        ->and(data_get(
            $version->snapshot,
            'integrations.notion.workspace.id',
        ))
        ->toBe($workspaceId)
        ->and(data_get(
            $version->snapshot,
            'integrations.notion.database.id',
        ))
        ->toBe($databaseId)
        ->and(data_get(
            $version->snapshot,
            'integrations.notion.data_source.id',
        ))
        ->toBe($dataSourceId)
        ->and(data_get(
            $version->snapshot,
            'integrations.notion.credential.configured',
        ))
        ->toBeTrue()
        ->and(data_get(
            $version->snapshot,
            'integrations.notion.credential.verified_version',
        ))
        ->toBe(1)
        ->and(data_get(
            $version->snapshot,
            'integrations.notion.verified_at',
        ))
        ->not->toBeNull();

    $ciphertext = (string) DB::table('provider_credentials')
        ->where('project_id', $this->project->id)
        ->value('secret_ciphertext');

    $snapshotJson = json_encode(
        $version->snapshot,
        JSON_THROW_ON_ERROR,
    );

    expect($snapshotJson)
        ->not->toContain($credential)
        ->not->toContain($ciphertext)
        ->not->toContain('secret_ciphertext');
});

test('changing the database creates a meaningful target revision', function (): void {
    $workspaceId =
        '17ab3186-873d-418f-b899-c3f6a43f68de';

    $firstDatabaseId =
        'd9824bdc-8445-4327-be8b-5b47500af6ce';

    $secondDatabaseId =
        '8a33450c-fd29-4b56-b199-51dde9291e07';

    $dataSourceId =
        '248104cd-477e-80af-bc30-000bd28de8f9';

    $this->mock(
        NotionConnectionGateway::class,
        function (MockInterface $mock) use (
            $workspaceId,
            $firstDatabaseId,
            $secondDatabaseId,
            $dataSourceId,
        ): void {
            $mock->shouldReceive('test')
                ->twice()
                ->andReturn(
                    NotionConnectionTestResult::connected(
                        workspaceId: $workspaceId,
                        workspaceName: 'AI Operating System',
                        databaseId: $firstDatabaseId,
                        databaseName: 'AIOS Tickets',
                        dataSourceId: $dataSourceId,
                        dataSourceName: 'Tickets',
                        providerRequestId: 'req-aios-174-database-one',
                    ),
                    NotionConnectionTestResult::connected(
                        workspaceId: $workspaceId,
                        workspaceName: 'AI Operating System',
                        databaseId: $secondDatabaseId,
                        databaseName: 'AIOS Delivery',
                        dataSourceId: $dataSourceId,
                        dataSourceName: 'Tickets',
                        providerRequestId: 'req-aios-174-database-two',
                    ),
                );
        },
    );

    $action = app(TestProjectNotionConnection::class);

    $action->handle(
        actorUserId: $this->user->id,
        organizationId: $this->organization->id,
        projectId: $this->project->id,
        databaseReference: $firstDatabaseId,
        plaintextCredential: 'secret_notion_database_change_abcdefghijklmnopqrstuvwxyz',
    );

    $action->handle(
        actorUserId: $this->user->id,
        organizationId: $this->organization->id,
        projectId: $this->project->id,
        databaseReference: $secondDatabaseId,
    );

    $firstVersion = $this->project
        ->configurationVersions()
        ->where('revision', 2)
        ->sole();

    $secondVersion = $this->project
        ->configurationVersions()
        ->where('revision', 3)
        ->sole();

    expect(data_get(
        $firstVersion->snapshot,
        'integrations.notion.database.id',
    ))
        ->toBe($firstDatabaseId)
        ->and(data_get(
            $secondVersion->snapshot,
            'integrations.notion.database.id',
        ))
        ->toBe($secondDatabaseId)
        ->and($firstVersion->snapshot)
        ->not->toBe($secondVersion->snapshot);
});

test('an approved workspace rebind records the new workspace', function (): void {
    $firstWorkspaceId =
        '17ab3186-873d-418f-b899-c3f6a43f68de';

    $secondWorkspaceId =
        '43bd390a-08b3-486e-a8c7-5f34eaf772a1';

    $firstDatabaseId =
        'd9824bdc-8445-4327-be8b-5b47500af6ce';

    $secondDatabaseId =
        '8a33450c-fd29-4b56-b199-51dde9291e07';

    $this->mock(
        NotionConnectionGateway::class,
        function (MockInterface $mock) use (
            $firstWorkspaceId,
            $secondWorkspaceId,
            $firstDatabaseId,
            $secondDatabaseId,
        ): void {
            $mock->shouldReceive('test')
                ->twice()
                ->andReturn(
                    NotionConnectionTestResult::connected(
                        workspaceId: $firstWorkspaceId,
                        workspaceName: 'Workspace One',
                        databaseId: $firstDatabaseId,
                        databaseName: 'Tickets One',
                        dataSourceId: '248104cd-477e-80af-bc30-000bd28de8f9',
                        dataSourceName: 'Tickets',
                        providerRequestId: 'req-workspace-one',
                    ),
                    NotionConnectionTestResult::connected(
                        workspaceId: $secondWorkspaceId,
                        workspaceName: 'Workspace Two',
                        databaseId: $secondDatabaseId,
                        databaseName: 'Tickets Two',
                        dataSourceId: '348104cd-477e-80af-bc30-000bd28de8f8',
                        dataSourceName: 'Tickets',
                        providerRequestId: 'req-workspace-two',
                    ),
                );
        },
    );

    $action = app(TestProjectNotionConnection::class);

    $action->handle(
        actorUserId: $this->user->id,
        organizationId: $this->organization->id,
        projectId: $this->project->id,
        databaseReference: $firstDatabaseId,
        plaintextCredential: 'secret_notion_workspace_rebind_abcdefghijklmnopqrstuvwxyz',
    );

    $action->handle(
        actorUserId: $this->user->id,
        organizationId: $this->organization->id,
        projectId: $this->project->id,
        databaseReference: $secondDatabaseId,
    );

    $latest = $this->project
        ->configurationVersions()
        ->where('revision', 3)
        ->sole();

    expect(data_get(
        $latest->snapshot,
        'integrations.notion.workspace.id',
    ))
        ->toBe($secondWorkspaceId)
        ->and(data_get(
            $latest->snapshot,
            'integrations.notion.database.id',
        ))
        ->toBe($secondDatabaseId);
});

test('repeating the same target and credential creates no duplicate revision', function (): void {
    $workspaceId =
        '17ab3186-873d-418f-b899-c3f6a43f68de';

    $databaseId =
        'd9824bdc-8445-4327-be8b-5b47500af6ce';

    $dataSourceId =
        '248104cd-477e-80af-bc30-000bd28de8f9';

    $this->mock(
        NotionConnectionGateway::class,
        function (MockInterface $mock) use (
            $workspaceId,
            $databaseId,
            $dataSourceId,
        ): void {
            $mock->shouldReceive('test')
                ->twice()
                ->andReturn(
                    NotionConnectionTestResult::connected(
                        workspaceId: $workspaceId,
                        workspaceName: 'AI Operating System',
                        databaseId: $databaseId,
                        databaseName: 'AIOS Tickets',
                        dataSourceId: $dataSourceId,
                        dataSourceName: 'Tickets',
                        providerRequestId: 'req-same-target-one',
                    ),
                    NotionConnectionTestResult::connected(
                        workspaceId: $workspaceId,
                        workspaceName: 'AI Operating System',
                        databaseId: $databaseId,
                        databaseName: 'AIOS Tickets',
                        dataSourceId: $dataSourceId,
                        dataSourceName: 'Tickets',
                        providerRequestId: 'req-same-target-two',
                    ),
                );
        },
    );

    $credential =
        'secret_notion_same_target_abcdefghijklmnopqrstuvwxyz';

    $action = app(TestProjectNotionConnection::class);

    $action->handle(
        actorUserId: $this->user->id,
        organizationId: $this->organization->id,
        projectId: $this->project->id,
        databaseReference: $databaseId,
        plaintextCredential: $credential,
    );

    $action->handle(
        actorUserId: $this->user->id,
        organizationId: $this->organization->id,
        projectId: $this->project->id,
        databaseReference: $databaseId,
        plaintextCredential: $credential,
    );

    expect(
        $this->project->configurationVersions()->count(),
    )
        ->toBe(2)
        ->and(
            $this->project
                ->configuration()
                ->firstOrFail()
                ->revision,
        )
        ->toBe(2);
});

test('a failed test preserves the last verified immutable target', function (): void {
    $workspaceId =
        '17ab3186-873d-418f-b899-c3f6a43f68de';

    $verifiedDatabaseId =
        'd9824bdc-8445-4327-be8b-5b47500af6ce';

    $failedDatabaseId =
        '8a33450c-fd29-4b56-b199-51dde9291e07';

    $this->mock(
        NotionConnectionGateway::class,
        function (MockInterface $mock) use (
            $workspaceId,
            $verifiedDatabaseId,
            $failedDatabaseId,
        ): void {
            $mock->shouldReceive('test')
                ->twice()
                ->andReturn(
                    NotionConnectionTestResult::connected(
                        workspaceId: $workspaceId,
                        workspaceName: 'AI Operating System',
                        databaseId: $verifiedDatabaseId,
                        databaseName: 'Verified Tickets',
                        dataSourceId: '248104cd-477e-80af-bc30-000bd28de8f9',
                        dataSourceName: 'Tickets',
                        providerRequestId: 'req-verified',
                    ),
                    NotionConnectionTestResult::failed(
                        failureCode: NotionConnectionFailureCode::DatabaseNotShared,
                        databaseId: $failedDatabaseId,
                        providerRequestId: 'req-failed',
                        workspaceId: $workspaceId,
                        workspaceName: 'AI Operating System',
                    ),
                );
        },
    );

    $action = app(TestProjectNotionConnection::class);

    $action->handle(
        actorUserId: $this->user->id,
        organizationId: $this->organization->id,
        projectId: $this->project->id,
        databaseReference: $verifiedDatabaseId,
        plaintextCredential: 'secret_notion_failure_preservation_abcdefghijklmnopqrstuvwxyz',
    );

    $verifiedVersion = $this->project
        ->configurationVersions()
        ->where('revision', 2)
        ->sole();

    $action->handle(
        actorUserId: $this->user->id,
        organizationId: $this->organization->id,
        projectId: $this->project->id,
        databaseReference: $failedDatabaseId,
    );

    expect(
        $this->project->configurationVersions()->count(),
    )
        ->toBe(2)
        ->and(data_get(
            $this->project
                ->latestConfigurationVersion()
                ->firstOrFail()
                ->snapshot,
            'integrations.notion.database.id',
        ))
        ->toBe($verifiedDatabaseId)
        ->and(
            $this->project
                ->latestConfigurationVersion()
                ->firstOrFail()
                ->snapshot,
        )
        ->toBe($verifiedVersion->snapshot);

    $integration = ProjectIntegration::query()
        ->where('project_id', $this->project->id)
        ->sole();

    /*
     * Current operational state may record the failed test, but the verified
     * target and immutable history are preserved.
     */
    expect($integration->database_id)
        ->toBe($verifiedDatabaseId)
        ->and($integration->verified_credential_version)
        ->toBe(1);
});
