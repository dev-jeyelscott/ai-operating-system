<?php

declare(strict_types=1);

namespace App\Application\Integrations;

use App\Application\Audit\RecordAuditEvent;
use App\Application\Integrations\Contracts\IntegrationCredentialCipher;
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
     * Inject encryption, auditing, and the transaction boundary.
     */
    public function __construct(
        private IntegrationCredentialCipher $cipher,
        private RecordAuditEvent $audit,
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

        /*
         * Validate again inside the application layer. HTTP validation is not
         * sufficient because future jobs and commands may invoke this action.
         */
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
                /*
                 * Lock the project row rather than only the credential row.
                 *
                 * A lock on a missing credential row cannot serialize two
                 * concurrent first-time inserts. The parent project always
                 * exists and therefore provides a stable aggregate lock.
                 */
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

                    /*
                     * Avoid unnecessary ciphertext churn and audit noise when
                     * the submitted credential is materially unchanged.
                     */
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
                    ])->save();

                    $this->audit->record(
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

                $this->audit->record(
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

                return $storedCredential->refresh();
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
