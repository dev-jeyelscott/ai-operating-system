<?php

declare(strict_types=1);

namespace App\Application\Integrations;

use App\Application\Audit\Data\AuditEventData;
use App\Application\Audit\RecordAuditEvent;
use App\Application\Integrations\Contracts\IntegrationCredentialCipher;
use App\Application\Notifications\RecordSecurityConfigurationNotification;
use App\Application\Shared\Contracts\TransactionManager;
use App\Domain\Audit\AuditActorType;
use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\AuditSubjectType;
use App\Domain\Integrations\IntegrationCredentialSecret;
use App\Domain\Integrations\IntegrationProvider;
use App\Models\Project;
use App\Models\ProviderCredential;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Creates or rotates one encrypted project integration credential.
 */
final readonly class SaveProjectIntegrationCredential
{
    /**
     * Inject encryption, auditing, notification, and transaction boundaries.
     */
    public function __construct(
        private IntegrationCredentialCipher $cipher,
        private RecordAuditEvent $audit,
        private RecordSecurityConfigurationNotification $notifications,
        private TransactionManager $transactions,
    ) {}

    /**
     * Store or rotate a project credential atomically.
     *
     * Repeating the same credential is idempotent. It does not create another
     * record, increment the version, change timestamps, or append another audit
     * event.
     */
    public function handle(
        int $actorUserId,
        int $organizationId,
        int $projectId,
        IntegrationProvider $provider,
        #[\SensitiveParameter]
        string $plaintextCredential,
        ?string $correlationId = null,
    ): ProviderCredential {
        $this->assertPositiveIdentifier($actorUserId, 'actor user');
        $this->assertPositiveIdentifier($organizationId, 'organization');
        $this->assertPositiveIdentifier($projectId, 'project');

        $submittedCredential = IntegrationCredentialSecret::from(
            $plaintextCredential,
        );

        return $this->transactions->run(
            function () use (
                $actorUserId,
                $organizationId,
                $projectId,
                $provider,
                $submittedCredential,
                $correlationId,
            ): ProviderCredential {
                $project = Project::query()
                    ->forOrganization($organizationId)
                    ->whereKey($projectId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($project->isArchived()) {
                    throw ValidationException::withMessages([
                        'credential' => 'Archived projects cannot configure integrations.',
                    ]);
                }

                $storedCredential = ProviderCredential::query()
                    ->forOrganization($organizationId)
                    ->forProject($project->id)
                    ->where('provider', $provider->value)
                    ->first();

                if ($storedCredential !== null) {
                    $existingCredential = $this->cipher->decrypt(
                        $storedCredential->secret_ciphertext,
                    );

                    if ($existingCredential->equals($submittedCredential)) {
                        return $storedCredential;
                    }

                    $storedCredential->forceFill([
                        'secret_ciphertext' => $this->cipher->encrypt(
                            $submittedCredential,
                        ),
                        'version' => $storedCredential->version + 1,
                        'last_rotated_by_user_id' => $actorUserId,
                        'rotated_at' => now(),
                        'last_connection_status' => null,
                        'last_connection_failure_code' => null,
                        'last_provider_request_id' => null,
                        'last_tested_by_user_id' => null,
                        'verified_credential_version' => null,
                        'last_tested_at' => null,
                    ])->save();

                    $event = $this->audit->record(
                        organizationId: $organizationId,
                        projectId: $project->id,
                        actorType: AuditActorType::User,
                        actorId: (string) $actorUserId,
                        eventType: AuditEventType::IntegrationCredentialRotated,
                        subjectType: AuditSubjectType::ProviderCredential,
                        subjectId: (string) $storedCredential->id,
                        correlationId: $correlationId,
                        metadata: [
                            'provider' => $provider->value,
                            'version' => $storedCredential->version,
                        ],
                    );

                    $this->recordCodexCredentialNotification(
                        event: $event,
                        actorUserId: $actorUserId,
                        project: $project,
                        provider: $provider,
                        action: 'rotated',
                    );

                    return $storedCredential->refresh();
                }

                $storedCredential = new ProviderCredential;
                $storedCredential->forceFill([
                    'organization_id' => $organizationId,
                    'project_id' => $project->id,
                    'provider' => $provider,
                    'secret_ciphertext' => $this->cipher->encrypt(
                        $submittedCredential,
                    ),
                    'version' => 1,
                    'created_by_user_id' => $actorUserId,
                    'last_rotated_by_user_id' => null,
                    'rotated_at' => null,
                ])->save();

                $event = $this->audit->record(
                    organizationId: $organizationId,
                    projectId: $project->id,
                    actorType: AuditActorType::User,
                    actorId: (string) $actorUserId,
                    eventType: AuditEventType::IntegrationCredentialStored,
                    subjectType: AuditSubjectType::ProviderCredential,
                    subjectId: (string) $storedCredential->id,
                    correlationId: $correlationId,
                    metadata: [
                        'provider' => $provider->value,
                        'version' => $storedCredential->version,
                    ],
                );

                $this->recordCodexCredentialNotification(
                    event: $event,
                    actorUserId: $actorUserId,
                    project: $project,
                    provider: $provider,
                    action: 'stored',
                );

                return $storedCredential->refresh();
            },
        );
    }

    /**
     * Emit a sanitized in-app notification only for Codex secret changes.
     */
    private function recordCodexCredentialNotification(
        AuditEventData $event,
        int $actorUserId,
        Project $project,
        IntegrationProvider $provider,
        string $action,
    ): void {
        if ($provider !== IntegrationProvider::Codex) {
            return;
        }

        $this->notifications->record(
            source: $event,
            recipientUserId: $actorUserId,
            title: 'Codex credential updated',
            message: sprintf(
                'The project-scoped Codex credential was %s. Run preflight before enabling Codex execution policy.',
                $action,
            ),
            actionUrl: route(
                'organizations.projects.integrations.index',
                [
                    'organization' => $project->organization,
                    'project' => $project,
                ],
            ),
            data: [
                'provider' => IntegrationProvider::Codex->value,
                'action' => $action,
            ],
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
