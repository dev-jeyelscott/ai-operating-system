<?php

declare(strict_types=1);

namespace App\Domain\Projects\Events;

use App\Domain\Events\DomainEvent;

/**
 * Reports that StartProject persisted one immutable project context snapshot.
 */
final readonly class ProjectContextSnapshotted implements DomainEvent
{
    /**
     * Store the immutable snapshot event payload.
     */
    public function __construct(
        public int $projectId,
        public int $projectContextSnapshotId,
        public int $configurationVersionId,
        public int $configurationRevision,
        public string $approvedDocumentSetFingerprint,
    ) {}

    /**
     * Return the stable event contract name.
     */
    public static function eventName(): string
    {
        return 'project.context_snapshotted';
    }

    /**
     * Return the payload schema version.
     */
    public static function schemaVersion(): int
    {
        return 1;
    }

    /**
     * Return the sanitized business payload.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'project_id' => $this->projectId,
            'project_context_snapshot_id' => $this->projectContextSnapshotId,
            'configuration_version_id' => $this->configurationVersionId,
            'configuration_revision' => $this->configurationRevision,
            'approved_document_set_fingerprint' => $this->approvedDocumentSetFingerprint,
        ];
    }
}
