<?php

declare(strict_types=1);

namespace App\Application\Integrations;

use App\Application\Audit\RecordAuditEvent;
use App\Application\Integrations\Contracts\IntegrationCredentialCipher;
use App\Application\Integrations\Contracts\NotionConnectionGateway;
use App\Application\Projects\SaveProjectSetupStep;
use App\Application\Shared\Contracts\TransactionManager;
use App\Domain\Audit\AuditActorType;
use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\AuditSubjectType;
use App\Domain\Integrations\IntegrationCredentialSecret;
use App\Domain\Integrations\IntegrationProvider;
use App\Domain\Integrations\NotionConnectionStatus;
use App\Domain\Integrations\NotionDatabaseId;
use App\Domain\Projects\ProjectSetupStep;
use App\Models\Project;
use App\Models\ProjectIntegration;
use App\Models\ProjectSetupProgress;
use App\Models\ProviderCredential;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Validates and persists one project's Notion connection state.
 */
final readonly class TestProjectNotionConnection
{
    /**
     * Inject credential, provider, audit, and transaction boundaries.
     */
    public function __construct(
        private IntegrationCredentialCipher $cipher,
        private NotionConnectionGateway $gateway,
        private SaveProjectIntegrationCredential $saveCredential,
        private SaveProjectSetupStep $saveSetupStep,
        private RecordAuditEvent $audit,
        private TransactionManager $transactions,
    ) {}

    /**
     * Test a submitted credential or the currently stored credential.
     *
     * A submitted replacement credential is stored only after Notion validates
     * it successfully. Failed candidate credentials are never persisted.
     */
    public function handle(
        int $actorUserId,
        int $organizationId,
        int $projectId,
        string $databaseReference,
        #[\SensitiveParameter]
        ?string $plaintextCredential = null,
        ?string $selectedDataSourceId = null,
        ?string $correlationId = null,
    ): NotionConnectionTestResult {
        $this->assertPositiveIdentifier(
            $actorUserId,
            'actor user',
        );

        $this->assertPositiveIdentifier(
            $organizationId,
            'organization',
        );

        $this->assertPositiveIdentifier(
            $projectId,
            'project',
        );

        $databaseId = NotionDatabaseId::from(
            $databaseReference,
        );

        $project = Project::query()
            ->forOrganization($organizationId)
            ->whereKey($projectId)
            ->firstOrFail();

        if ($project->isArchived()) {
            throw ValidationException::withMessages([
                'connection' => 'Archived projects cannot test integrations.',
            ]);
        }

        $setupProgress = ProjectSetupProgress::query()
            ->where('project_id', $project->id)
            ->firstOrFail();

        if (! $setupProgress->hasCompleted(ProjectSetupStep::Repository)) {
            throw ValidationException::withMessages([
                'step' => 'Complete "Repository" before continuing to "Integrations".',
            ]);
        }

        $storedCredential = ProviderCredential::query()
            ->forOrganization($organizationId)
            ->forProject($projectId)
            ->where(
                'provider',
                IntegrationProvider::Notion->value,
            )
            ->first();

        $submittedCredential = $plaintextCredential !== null
            ? IntegrationCredentialSecret::from(
                $plaintextCredential,
            )
            : null;

        if (
            $submittedCredential === null
            && $storedCredential === null
        ) {
            throw ValidationException::withMessages([
                'credential' => 'Store or enter a Notion integration credential before testing the connection.',
            ]);
        }

        $credential = $submittedCredential
            ?? $this->cipher->decrypt(
                $storedCredential->secret_ciphertext,
            );

        /*
         * Capture the version so a concurrent rotation cannot cause a stale test
         * result to be persisted against a different credential.
         */
        $testedCredentialVersion = $submittedCredential === null
            ? $storedCredential->version
            : null;

        $existingIntegration = ProjectIntegration::query()
            ->forOrganization($organizationId)
            ->forProject($projectId)
            ->where(
                'provider',
                IntegrationProvider::Notion->value,
            )
            ->first();

        $result = $this->gateway->test(
            credential: $credential,
            databaseId: $databaseId,
            expectedWorkspaceId: $existingIntegration?->workspace_id,
            selectedDataSourceId: $selectedDataSourceId,
        );

        if ($result->dataSourceCandidates !== []) {
            return $result;
        }

        return $this->transactions->run(
            function () use (
                $actorUserId,
                $organizationId,
                $projectId,
                $submittedCredential,
                $testedCredentialVersion,
                $result,
                $correlationId,
            ): NotionConnectionTestResult {
                $project = Project::query()
                    ->forOrganization($organizationId)
                    ->whereKey($projectId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($project->isArchived()) {
                    throw ValidationException::withMessages([
                        'connection' => 'The project was archived while the connection was being tested.',
                    ]);
                }

                if ($testedCredentialVersion !== null) {
                    $currentCredential =
                        ProviderCredential::query()
                            ->forOrganization($organizationId)
                            ->forProject($projectId)
                            ->where(
                                'provider',
                                IntegrationProvider::Notion->value,
                            )
                            ->lockForUpdate()
                            ->first();

                    if (
                        $currentCredential === null
                        || $currentCredential->version
                            !== $testedCredentialVersion
                    ) {
                        throw ValidationException::withMessages([
                            'connection' => 'The Notion credential changed while the connection was being tested. Run the test again.',
                        ]);
                    }
                }

                $verifiedCredentialVersion = $testedCredentialVersion;

                if ($result->successful && $submittedCredential !== null) {
                    $storedCredential = $this->saveCredential->handle(
                        actorUserId: $actorUserId,
                        organizationId: $organizationId,
                        projectId: $projectId,
                        provider: IntegrationProvider::Notion,
                        plaintextCredential: $submittedCredential->reveal(),
                        correlationId: $correlationId,
                    );

                    $verifiedCredentialVersion = $storedCredential->version;
                }

                if (
                    $result->successful
                    && $verifiedCredentialVersion === null
                ) {
                    throw ValidationException::withMessages([
                        'connection' => 'The verified Notion credential version could not be resolved.',
                    ]);
                }

                $integration = ProjectIntegration::query()
                    ->forOrganization($organizationId)
                    ->forProject($projectId)
                    ->where(
                        'provider',
                        IntegrationProvider::Notion->value,
                    )
                    ->lockForUpdate()
                    ->first();

                if ($integration === null) {
                    $integration = new ProjectIntegration;

                    $integration->forceFill([
                        'organization_id' => $organizationId,
                        'project_id' => $projectId,
                        'provider' => IntegrationProvider::Notion,
                    ]);
                }

                /*
                 * Only a successful target change counts as a material project
                 * configuration revision.
                 */
                $configurationChanged =
                    $result->successful
                    && (
                        ! $integration->exists
                        || $integration->workspace_id
                            !== $result->workspaceId
                        || $integration->database_id
                            !== $result->databaseId
                        || $integration->data_source_id
                            !== $result->dataSourceId
                        || $integration->verified_credential_version
                            !== $verifiedCredentialVersion
                    );

                if (
                    $result->successful
                    && ! $configurationChanged
                    && $integration->connection_status
                        === NotionConnectionStatus::Connected
                    && $integration->last_failure_code === null
                    && $integration->workspace_id === $result->workspaceId
                    && $integration->workspace_name === $result->workspaceName
                    && $integration->database_id === $result->databaseId
                    && $integration->database_name === $result->databaseName
                    && $integration->data_source_id === $result->dataSourceId
                    && $integration->data_source_name === $result->dataSourceName
                    && $integration->verified_credential_version
                        === $verifiedCredentialVersion
                ) {
                    $this->saveSetupStep->handle(
                        actorUserId: $actorUserId,
                        organizationId: $organizationId,
                        projectId: $projectId,
                        step: ProjectSetupStep::Integrations,
                        payload: [],
                        correlationId: $correlationId,
                        externalConfigurationChanged: false,
                    );

                    return $result->withConfigurationChanged(false);
                }

                $attributes = [
                    'connection_status' => $result->successful
                        ? NotionConnectionStatus::Connected
                        : NotionConnectionStatus::Failed,

                    'last_failure_code' => $result->failureCode,

                    'last_provider_request_id' => $result->providerRequestId,

                    'last_tested_by_user_id' => $actorUserId,

                    'last_tested_at' => now(),
                ];

                if ($result->successful) {
                    $attributes = [
                        ...$attributes,

                        'workspace_id' => $result->workspaceId,
                        'workspace_name' => $result->workspaceName,

                        'database_id' => $result->databaseId,
                        'database_name' => $result->databaseName,

                        'data_source_id' => $result->dataSourceId,
                        'data_source_name' => $result->dataSourceName,

                        'verified_credential_version' => $verifiedCredentialVersion,

                        'last_connected_at' => now(),
                    ];
                } elseif (! $integration->exists) {
                    /*
                     * Preserve a normalized attempted database ID for the first
                     * failure, but do not overwrite a previously connected target.
                     */
                    $attributes['database_id'] =
                        $result->databaseId;
                }

                $integration->forceFill($attributes)->save();

                $this->audit->record(
                    organizationId: $organizationId,
                    projectId: $projectId,
                    actorType: AuditActorType::User,
                    actorId: (string) $actorUserId,
                    eventType: $result->successful
                        ? AuditEventType::NotionConnectionTestSucceeded
                        : AuditEventType::NotionConnectionTestFailed,
                    subjectType: AuditSubjectType::ProjectIntegration,
                    subjectId: (string) $integration->id,
                    correlationId: $correlationId,
                    metadata: [
                        'provider' => IntegrationProvider::Notion->value,

                        'status' => $result->successful
                            ? NotionConnectionStatus::Connected->value
                            : NotionConnectionStatus::Failed->value,

                        'failure_code' => $result->failureCode?->value,

                        'database_id' => $result->databaseId,

                        'data_source_id' => $result->dataSourceId,

                        'provider_request_id' => $result->providerRequestId,

                        'verified_credential_version' => $result->successful ? $verifiedCredentialVersion : null,
                    ],
                );

                if ($result->successful) {
                    $this->saveSetupStep->handle(
                        actorUserId: $actorUserId,
                        organizationId: $organizationId,
                        projectId: $projectId,
                        step: ProjectSetupStep::Integrations,
                        payload: [],
                        correlationId: $correlationId,
                        externalConfigurationChanged: $configurationChanged,
                    );
                }

                return $result->withConfigurationChanged(
                    $configurationChanged,
                );
            },
        );
    }

    /**
     * Reject invalid internal command identifiers.
     */
    private function assertPositiveIdentifier(
        int $identifier,
        string $name,
    ): void {
        if ($identifier < 1) {
            throw new InvalidArgumentException(
                "The {$name} identifier must be positive.",
            );
        }
    }
}
