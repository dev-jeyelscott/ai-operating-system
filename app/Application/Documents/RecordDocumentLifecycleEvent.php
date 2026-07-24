<?php

declare(strict_types=1);

namespace App\Application\Documents;

use App\Application\Audit\Data\AuditContext;
use App\Application\Audit\Data\AuditEventData;
use App\Application\Audit\RecordAuditEvent;
use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\AuditSubjectType;
use App\Models\DocumentVersion;
use App\Models\Project;
use App\Models\ProjectContextSnapshot;

/**
 * Builds safe, stable audit payloads for the authoritative document lifecycle.
 */
final readonly class RecordDocumentLifecycleEvent
{
    private const SCHEMA_VERSION = 1;

    public function __construct(
        private RecordAuditEvent $audit,
    ) {}

    /**
     * Record one document-version lifecycle transition.
     *
     * Document bodies, parsed content, summaries, storage paths, original
     * filenames, prompts, and raw failure messages are deliberately excluded.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function version(
        DocumentVersion $version,
        AuditEventType $eventType,
        AuditContext $context,
        array $metadata = [],
    ): AuditEventData {
        $version->loadMissing('document.project');

        $document = $version->document;
        $project = $document->project;

        $safeIdentity = array_filter(
            [
                'document_id' => $document->id,
                'document_version_id' => $version->id,
                'version' => $version->version,
                'document_class' => $document->document_class,
                'status' => $version->status->value,
                'classification' => $version->classification->value,
                'checksum_sha256' => $version->checksum_sha256,
                'media_type' => $version->media_type,
                'byte_size' => $version->byte_size,
                'failure_code' => $version->failure_code,
            ],
            static fn (mixed $value): bool => $value !== null,
        );

        return $this->audit->record(
            organizationId: $project->organization_id,
            projectId: $project->id,
            actorType: $context->actorType,
            actorId: $context->actorId,
            eventType: $eventType,
            subjectType: AuditSubjectType::DocumentVersion,
            subjectId: (string) $version->id,
            correlationId: $context->correlationId,
            metadata: [
                ...$metadata,
                ...$safeIdentity,
            ],
            causationId: $context->causationId,
            executionId: $context->executionId,
            schemaVersion: self::SCHEMA_VERSION,
            deduplicationKey: $this->deduplicationKey(
                subjectType: AuditSubjectType::DocumentVersion,
                subjectId: $version->id,
                eventType: $eventType,
                context: $context,
            ),
        );
    }

    /**
     * Record creation of a new immutable project context snapshot.
     */
    public function snapshot(
        Project $project,
        ProjectContextSnapshot $snapshot,
        AuditContext $context,
    ): AuditEventData {
        return $this->audit->record(
            organizationId: $project->organization_id,
            projectId: $project->id,
            actorType: $context->actorType,
            actorId: $context->actorId,
            eventType: AuditEventType::ProjectContextSnapshotCreated,
            subjectType: AuditSubjectType::ProjectContextSnapshot,
            subjectId: (string) $snapshot->id,
            correlationId: $context->correlationId,
            metadata: [
                'project_context_snapshot_id' => $snapshot->id,
                'project_configuration_version_id' => $snapshot->project_configuration_version_id,
                'configuration_revision' => $snapshot->configuration_revision,
                'identity_schema_version' => $snapshot->identity_schema_version,
                'approved_document_set_fingerprint' => $snapshot->approved_document_set_fingerprint,
                'approved_document_count' => count(
                    $snapshot->approved_document_versions,
                ),
            ],
            causationId: $context->causationId,
            executionId: $context->executionId,
            schemaVersion: self::SCHEMA_VERSION,
            deduplicationKey: $this->deduplicationKey(
                subjectType: AuditSubjectType::ProjectContextSnapshot,
                subjectId: $snapshot->id,
                eventType: AuditEventType::ProjectContextSnapshotCreated,
                context: $context,
            ),
        );
    }

    /**
     * Build a bounded key that prevents duplicate material events.
     */
    private function deduplicationKey(
        AuditSubjectType $subjectType,
        int $subjectId,
        AuditEventType $eventType,
        AuditContext $context,
    ): ?string {
        $traceIdentity = $context->causationId
            ?? $context->correlationId
            ?? $context->executionId;

        if ($traceIdentity === null) {
            return null;
        }

        return sprintf(
            '%s:%d:%s:%s',
            $subjectType->value,
            $subjectId,
            $eventType->value,
            substr(hash('sha256', $traceIdentity), 0, 24),
        );
    }
}
