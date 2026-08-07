<?php

declare(strict_types=1);

namespace App\Application\Integrations;

use App\Application\Audit\RecordAuditEvent;
use App\Application\Integrations\Contracts\CodexConnectionGateway;
use App\Application\Integrations\Contracts\IntegrationCredentialCipher;
use App\Application\Notifications\RecordSecurityConfigurationNotification;
use App\Application\Shared\Contracts\TransactionManager;
use App\Domain\Audit\AuditActorType;
use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\AuditSubjectType;
use App\Domain\Integrations\IntegrationCredentialSecret;
use App\Domain\Integrations\IntegrationProvider;
use App\Domain\Projects\Configuration\CodexProviderPolicy;
use App\Models\Project;
use App\Models\ProjectConfiguration;
use App\Models\ProviderCredential;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Validates and persists one project's sanitized Codex preflight state.
 */
final readonly class TestProjectCodexConnection
{
    /**
     * Inject credential, provider, audit, notification, and transaction boundaries.
     */
    public function __construct(
        private IntegrationCredentialCipher $cipher,
        private CodexConnectionGateway $gateway,
        private SaveProjectIntegrationCredential $saveCredential,
        private RecordAuditEvent $audit,
        private RecordSecurityConfigurationNotification $notifications,
        private TransactionManager $transactions,
    ) {}

    /**
     * Test a submitted candidate or the currently stored Codex credential.
     *
     * Candidate credentials are persisted only after successful provider
     * validation. External I/O occurs outside the database transaction.
     */
    public function handle(
        int $actorUserId,
        int $organizationId,
        int $projectId,
        #[\SensitiveParameter]
        ?string $plaintextCredential = null,
        ?string $correlationId = null,
    ): CodexConnectionTestResult {
        $this->assertPositiveIdentifier($actorUserId, 'actor user');
        $this->assertPositiveIdentifier($organizationId, 'organization');
        $this->assertPositiveIdentifier($projectId, 'project');

        $project = Project::query()
            ->forOrganization($organizationId)
            ->whereKey($projectId)
            ->firstOrFail();

        if ($project->isArchived()) {
            throw ValidationException::withMessages([
                'connection' => 'Archived projects cannot test integrations.',
            ]);
        }

        $configuration = ProjectConfiguration::query()
            ->where('project_id', $project->id)
            ->firstOrFail();

        $codexPolicy = Arr::get(
            $configuration->provider_policy,
            'codex',
            CodexProviderPolicy::defaults(),
        );

        if (! is_array($codexPolicy)) {
            throw ValidationException::withMessages([
                'connection' => 'The persisted Codex provider policy is malformed.',
            ]);
        }

        $policy = CodexProviderPolicy::fromArray($codexPolicy);
        $modelIdentifier = $policy->modelIdentifier;
        $testedConfigurationRevision = $configuration->revision;

        $storedCredential = ProviderCredential::query()
            ->forOrganization($organizationId)
            ->forProject($project->id)
            ->where('provider', IntegrationProvider::Codex->value)
            ->first();

        $submittedCredential = $plaintextCredential !== null
            ? IntegrationCredentialSecret::from($plaintextCredential)
            : null;

        if ($submittedCredential === null && $storedCredential === null) {
            throw ValidationException::withMessages([
                'credential' => 'Enter a Codex credential before running the first preflight.',
            ]);
        }

        $credential = $submittedCredential
            ?? $this->cipher->decrypt($storedCredential->secret_ciphertext);

        $testedCredentialVersion = $submittedCredential === null
            ? $storedCredential->version
            : null;

        $result = $this->gateway->test(
            credential: $credential,
            modelIdentifier: $modelIdentifier,
        );

        /*
         * A failed candidate is intentionally not persisted, preventing a bad
         * replacement from destroying a previously working stored credential.
         */
        if (! $result->successful && $submittedCredential !== null) {
            return $result;
        }

        return $this->transactions->run(
            function () use (
                $actorUserId,
                $organizationId,
                $projectId,
                $submittedCredential,
                $testedCredentialVersion,
                $testedConfigurationRevision,
                $result,
                $correlationId,
            ): CodexConnectionTestResult {
                $project = Project::query()
                    ->forOrganization($organizationId)
                    ->whereKey($projectId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($project->isArchived()) {
                    throw ValidationException::withMessages([
                        'connection' => 'The project was archived while Codex preflight was running.',
                    ]);
                }

                $currentConfiguration = ProjectConfiguration::query()
                    ->where('project_id', $projectId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($currentConfiguration->revision !== $testedConfigurationRevision) {
                    throw ValidationException::withMessages([
                        'connection' => 'Project configuration changed while Codex preflight was running. Run the test again.',
                    ]);
                }

                if ($testedCredentialVersion !== null) {
                    $currentCredential = ProviderCredential::query()
                        ->forOrganization($organizationId)
                        ->forProject($projectId)
                        ->where('provider', IntegrationProvider::Codex->value)
                        ->lockForUpdate()
                        ->first();

                    if (
                        $currentCredential === null
                        || $currentCredential->version !== $testedCredentialVersion
                    ) {
                        throw ValidationException::withMessages([
                            'connection' => 'The Codex credential changed while preflight was running. Run the test again.',
                        ]);
                    }
                }

                $verifiedCredentialVersion = $testedCredentialVersion;

                if ($result->successful && $submittedCredential !== null) {
                    $storedCredential = $this->saveCredential->handle(
                        actorUserId: $actorUserId,
                        organizationId: $organizationId,
                        projectId: $projectId,
                        provider: IntegrationProvider::Codex,
                        plaintextCredential: $submittedCredential->reveal(),
                        correlationId: $correlationId,
                    );

                    $verifiedCredentialVersion = $storedCredential->version;
                }

                $credential = ProviderCredential::query()
                    ->forOrganization($organizationId)
                    ->forProject($projectId)
                    ->where('provider', IntegrationProvider::Codex->value)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($result->successful && $verifiedCredentialVersion === null) {
                    throw ValidationException::withMessages([
                        'connection' => 'The verified Codex credential version could not be resolved.',
                    ]);
                }

                $nextStatus = $result->successful ? 'connected' : 'failed';
                $nextFailure = $result->failureCode?->value;
                $nextVerifiedVersion = $result->successful
                    ? $verifiedCredentialVersion
                    : ($result->failureCode?->invalidatesVerification() === true
                        ? null
                        : $credential->verified_credential_version);

                $configurationChanged =
                    $credential->last_connection_status !== $nextStatus
                    || $credential->last_connection_failure_code !== $nextFailure
                    || $credential->last_provider_request_id !== $result->providerRequestId
                    || $credential->verified_credential_version !== $nextVerifiedVersion;

                $credential->forceFill([
                    'last_connection_status' => $nextStatus,
                    'last_connection_failure_code' => $nextFailure,
                    'last_provider_request_id' => $result->providerRequestId,
                    'last_tested_by_user_id' => $actorUserId,
                    'verified_credential_version' => $nextVerifiedVersion,
                    'last_tested_at' => now(),
                    'last_connected_at' => $result->successful
                        ? now()
                        : $credential->last_connected_at,
                ])->save();

                $event = $this->audit->record(
                    organizationId: $organizationId,
                    projectId: $projectId,
                    actorType: AuditActorType::User,
                    actorId: (string) $actorUserId,
                    eventType: $result->successful
                        ? AuditEventType::CodexConnectionTestSucceeded
                        : AuditEventType::CodexConnectionTestFailed,
                    subjectType: AuditSubjectType::ProviderCredential,
                    subjectId: (string) $credential->id,
                    correlationId: $correlationId,
                    metadata: [
                        'provider' => IntegrationProvider::Codex->value,
                        'status' => $nextStatus,
                        'failure_code' => $nextFailure,
                        'model_identifier' => $result->modelIdentifier,
                        'provider_request_id' => $result->providerRequestId,
                        'verified_credential_version' => $nextVerifiedVersion,
                    ],
                );

                $this->notifications->record(
                    source: $event,
                    recipientUserId: $actorUserId,
                    title: $result->successful
                        ? 'Codex preflight succeeded'
                        : 'Codex preflight failed',
                    message: $result->successful
                        ? 'The project-scoped Codex credential can access the configured model.'
                        : $result->userMessage(),
                    actionUrl: route(
                        'organizations.projects.integrations.index',
                        [
                            'organization' => $project->organization,
                            'project' => $project,
                        ],
                    ),
                    data: [
                        'provider' => IntegrationProvider::Codex->value,
                        'status' => $nextStatus,
                        'failure_code' => $nextFailure,
                        'model_identifier' => $result->modelIdentifier,
                    ],
                );

                return $result->withConfigurationChanged($configurationChanged);
            },
        );
    }

    /**
     * Reject invalid internal command identifiers.
     */
    private function assertPositiveIdentifier(int $identifier, string $name): void
    {
        if ($identifier < 1) {
            throw new InvalidArgumentException(
                "The {$name} identifier must be positive.",
            );
        }
    }
}
