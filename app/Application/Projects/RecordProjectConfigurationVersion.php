<?php

declare(strict_types=1);

namespace App\Application\Projects;

use App\Application\Audit\RecordAuditEvent;
use App\Application\Shared\Contracts\TransactionManager;
use App\Domain\Audit\AuditActorType;
use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\AuditSubjectType;
use App\Models\Project;
use App\Models\ProjectConfiguration;
use App\Models\ProjectConfigurationVersion;
use InvalidArgumentException;

/**
 * Appends one immutable configuration snapshot and matching audit event.
 */
final readonly class RecordProjectConfigurationVersion
{
    private const MAX_IDENTIFIER_LENGTH = 191;

    /**
     * Inject audit persistence and the application transaction boundary.
     */
    public function __construct(
        private BuildProjectConfigurationSnapshot $snapshots,
        private RecordAuditEvent $audit,
        private TransactionManager $transactions,
    ) {}

    /**
     * Record the project's current configuration revision atomically.
     *
     * The current configuration row is locked so concurrent configuration
     * updates cannot serialize the same revision from different states.
     */
    public function handle(
        int $organizationId,
        int $projectId,
        AuditActorType $actorType,
        string $actorId,
        string $changeReason,
        ?string $correlationId = null,
    ): ProjectConfigurationVersion {
        if ($organizationId < 1) {
            throw new InvalidArgumentException(
                'The organization identifier must be positive.',
            );
        }

        if ($projectId < 1) {
            throw new InvalidArgumentException(
                'The project identifier must be positive.',
            );
        }

        $normalizedActorId = $this->normalizeActorId($actorId);
        $normalizedChangeReason = $this->normalizeChangeReason(
            $changeReason,
        );

        return $this->transactions->run(
            function () use (
                $organizationId,
                $projectId,
                $actorType,
                $normalizedActorId,
                $normalizedChangeReason,
                $correlationId,
            ): ProjectConfigurationVersion {
                /*
                 * Resolve the project through its mandatory organization scope.
                 * Configuration history must never be recorded through an
                 * unscoped cross-tenant identifier.
                 */
                $project = Project::query()
                    ->forOrganization($organizationId)
                    ->whereKey($projectId)
                    ->lockForUpdate()
                    ->firstOrFail();

                /*
                 * Serialize concurrent configuration-history writes using the
                 * authoritative current configuration row.
                 */
                $configuration = ProjectConfiguration::query()
                    ->where('project_id', $project->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $snapshot = $this->snapshots->handle(
                    organizationId: $organizationId,
                    configuration: $configuration,
                );

                $version = ProjectConfigurationVersion::query()->create([
                    'project_id' => $project->id,
                    'schema_version' => $configuration->schema_version,
                    'revision' => $configuration->revision,
                    'actor_type' => $actorType,
                    'actor_id' => $normalizedActorId,
                    'change_reason' => $normalizedChangeReason,
                    'snapshot' => $snapshot->toArray(),
                    'created_at' => now(),
                ]);

                /*
                 * Do not duplicate the complete snapshot in audit metadata.
                 *
                 * The history row owns the snapshot while the audit event points
                 * to it using bounded, credential-free identifiers.
                 */
                $this->audit->record(
                    organizationId: $organizationId,
                    projectId: $project->id,
                    actorType: $actorType,
                    actorId: $normalizedActorId,
                    eventType: AuditEventType::ProjectConfigurationVersionCreated,
                    subjectType: AuditSubjectType::ProjectConfigurationVersion,
                    subjectId: (string) $version->id,
                    correlationId: $correlationId,
                    metadata: [
                        'configuration_version_id' => $version->id,
                        'schema_version' => $version->schema_version,
                        'revision' => $version->revision,
                        'change_reason' => $version->change_reason,
                    ],
                );

                return $version;
            },
        );
    }

    /**
     * Normalize a historical actor identifier.
     */
    private function normalizeActorId(string $actorId): string
    {
        $normalized = trim($actorId);

        if ($normalized === '') {
            throw new InvalidArgumentException(
                'The configuration-version actor identifier is required.',
            );
        }

        if (mb_strlen($normalized) > self::MAX_IDENTIFIER_LENGTH) {
            throw new InvalidArgumentException(
                'The configuration-version actor identifier may not exceed '
                .self::MAX_IDENTIFIER_LENGTH
                .' characters.',
            );
        }

        return $normalized;
    }

    /**
     * Normalize the stable machine-readable reason for the version.
     */
    private function normalizeChangeReason(string $changeReason): string
    {
        $normalized = trim($changeReason);

        if ($normalized === '') {
            throw new InvalidArgumentException(
                'The configuration-version change reason is required.',
            );
        }

        if (mb_strlen($normalized) > self::MAX_IDENTIFIER_LENGTH) {
            throw new InvalidArgumentException(
                'The configuration-version change reason may not exceed '
                .self::MAX_IDENTIFIER_LENGTH
                .' characters.',
            );
        }

        return $normalized;
    }
}
