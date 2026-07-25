<?php

declare(strict_types=1);

namespace App\Application\Projects;

use App\Application\Audit\Data\AuditContext;
use App\Application\Documents\ApprovedDocumentSetFingerprint;
use App\Application\Documents\Exceptions\DocumentContextIntegrityException;
use App\Application\Documents\RecordDocumentLifecycleEvent;
use App\Domain\Documents\DocumentStatus;
use App\Models\DocumentVersion;
use App\Models\Project;
use App\Models\ProjectConfigurationVersion;
use App\Models\ProjectContextSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Freezes one exact configuration revision and approved document input set.
 *
 * @phpstan-type SnapshotEntry array{
 *     document_id: int,
 *     document_version_id: int,
 *     version: int,
 *     checksum_sha256: string,
 *     parsed_content_checksum_sha256: string,
 *     parsed_content_storage_disk: string,
 *     parsed_content_storage_path: string,
 *     analysis_flags: list<string>
 * }
 */
final readonly class CreateProjectContextSnapshot
{
    public function __construct(
        private ApprovedDocumentSetFingerprint $fingerprint,
        private RecordDocumentLifecycleEvent $events,
    ) {}

    /**
     * Return an identical existing snapshot or append a new immutable snapshot.
     */
    public function handle(
        int $organizationId,
        int $projectId,
        ?AuditContext $auditContext = null,
    ): ProjectContextSnapshot {
        $auditContext ??= AuditContext::system(actorId: 'context-snapshot-command');

        return DB::transaction(
            function () use (
                $organizationId,
                $projectId,
                $auditContext,
            ): ProjectContextSnapshot {
                /*
                 * Lock the project aggregate so concurrent snapshot requests
                 * for the same project serialize before identity calculation.
                 */
                $project = Project::query()
                    ->forOrganization($organizationId)
                    ->whereKey($projectId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $configurationVersion = ProjectConfigurationVersion::query()
                    ->where(
                        'project_id',
                        $project->id,
                    )
                    ->orderByDesc('revision')
                    ->lockForUpdate()
                    ->firstOrFail();

                $approvedDocumentVersions =
                    $this->approvedDocumentVersions(
                        organizationId: $organizationId,
                        project: $project,
                    );

                $documentSetFingerprint =
                    $this->fingerprint->handle(
                        $approvedDocumentVersions,
                    );

                $existingSnapshot =
                    ProjectContextSnapshot::query()
                        ->where(
                            'project_id',
                            $project->id,
                        )
                        ->where(
                            'project_configuration_version_id',
                            $configurationVersion->id,
                        )
                        ->where(
                            'identity_schema_version',
                            ApprovedDocumentSetFingerprint::SCHEMA_VERSION,
                        )
                        ->where(
                            'approved_document_set_fingerprint',
                            $documentSetFingerprint,
                        )
                        ->first();

                if (
                    $existingSnapshot
                    instanceof ProjectContextSnapshot
                ) {
                    /*
                    * The fingerprint should imply equality. Compare both complete canonical
                    * sets as a final fail-closed collision guard without depending on jsonb
                    * object-key ordering.
                    */
                    if (
                        $this->fingerprint->canonicalize(
                            $existingSnapshot->approved_document_versions,
                        )
                        !== $this->fingerprint->canonicalize(
                            $approvedDocumentVersions,
                        )
                    ) {
                        throw DocumentContextIntegrityException::snapshotIdentityConflict(
                            $existingSnapshot->id,
                        );
                    }

                    return $existingSnapshot;
                }

                $snapshot = ProjectContextSnapshot::query()
                    ->create([
                        'project_id' => $project->id,
                        'project_configuration_version_id' => $configurationVersion->id,
                        'configuration_revision' => $configurationVersion->revision,
                        'identity_schema_version' => ApprovedDocumentSetFingerprint::SCHEMA_VERSION,
                        'approved_document_set_fingerprint' => $documentSetFingerprint,
                        'approved_document_versions' => $approvedDocumentVersions,
                    ]);

                $this->events->snapshot(
                    project: $project,
                    snapshot: $snapshot,
                    context: $auditContext,
                );

                return $snapshot;
            },
            attempts: 3,
        );
    }

    /**
     * Build the canonical approved-document set and immutable artifacts.
     *
     * @return list<SnapshotEntry>
     */
    private function approvedDocumentVersions(
        int $organizationId,
        Project $project,
    ): array {
        $versions = DocumentVersion::query()
            ->where(
                'status',
                DocumentStatus::Approved->value,
            )
            ->whereNotNull('parsed_content')
            ->whereNotNull('analyzer_name')
            ->whereNotNull('analyzer_version')
            ->whereNotNull('analysis_seed')
            ->whereNotNull('analysis_completed_at')
            ->whereNotNull('analysis_summary')
            ->whereNotNull('analysis_conflicts')
            ->whereNotNull('analysis_gaps')
            ->whereNotNull('analysis_flags')
            ->whereHas(
                'document',
                fn ($query) => $query->where(
                    'project_id',
                    $project->id,
                ),
            )
            ->orderBy('document_id')
            ->orderBy('version')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $artifactDisk = trim(
            (string) config('filesystems.artifact'),
        );

        if ($artifactDisk === '') {
            throw new DocumentContextIntegrityException(
                'The immutable document artifact disk is not configured.',
            );
        }

        /** @var list<SnapshotEntry> $approvedDocumentVersions */
        $approvedDocumentVersions = [];

        foreach ($versions as $version) {
            $parsedContent = $version->parsed_content;

            if (! is_string($parsedContent)) {
                throw DocumentContextIntegrityException::sourceContentUnavailable(
                    $version->id,
                );
            }

            $parsedContentChecksum = hash(
                'sha256',
                $parsedContent,
            );

            $artifactPath = sprintf(
                'document-context/organizations/%d/projects/%d/document-versions/%d/%s.txt',
                $organizationId,
                $project->id,
                $version->id,
                $parsedContentChecksum,
            );

            $this->persistImmutableArtifact(
                version: $version,
                disk: $artifactDisk,
                path: $artifactPath,
                content: $parsedContent,
                checksum: $parsedContentChecksum,
            );

            /*
             * Array append guarantees consecutive integer keys, allowing
             * PHPStan to prove this value is a list<SnapshotEntry>.
             */
            $approvedDocumentVersions[] = [
                'document_id' => (int) $version->document_id,
                'document_version_id' => (int) $version->id,
                'version' => (int) $version->version,
                'checksum_sha256' => (string) $version->checksum_sha256,
                'parsed_content_checksum_sha256' => $parsedContentChecksum,
                'parsed_content_storage_disk' => $artifactDisk,
                'parsed_content_storage_path' => $artifactPath,
                'analysis_flags' => $this->normalizeFlags(
                    $version->analysis_flags,
                    $version->id,
                ),
            ];
        }

        return $approvedDocumentVersions;
    }

    /**
     * Write a content-addressed artifact and verify the stored bytes.
     *
     * Existing objects are never blindly overwritten. This makes retries safe
     * and detects corruption or storage-key collisions.
     */
    private function persistImmutableArtifact(
        DocumentVersion $version,
        string $disk,
        string $path,
        string $content,
        string $checksum,
    ): void {
        try {
            $filesystem = Storage::disk($disk);

            if (! $filesystem->exists($path)) {
                $written = $filesystem->put(
                    $path,
                    $content,
                );

                if (! $written) {
                    throw DocumentContextIntegrityException::artifactWriteFailed(
                        $version->id,
                    );
                }
            }

            $storedContent = $filesystem->get($path);
        } catch (
            DocumentContextIntegrityException $exception
        ) {
            throw $exception;
        } catch (Throwable $exception) {
            throw DocumentContextIntegrityException::artifactWriteFailed(
                $version->id,
                $exception,
            );
        }

        if (
            ! hash_equals(
                $checksum,
                hash(
                    'sha256',
                    $storedContent,
                ),
            )
        ) {
            throw DocumentContextIntegrityException::artifactChecksumMismatch(
                $version->id,
            );
        }
    }

    /**
     * Canonicalize safety flags so ordering cannot change snapshot identity.
     *
     * @return list<string>
     */
    private function normalizeFlags(
        mixed $flags,
        int $documentVersionId,
    ): array {
        if (
            ! is_array($flags)
            || ! array_is_list($flags)
        ) {
            throw DocumentContextIntegrityException::sourceContentUnavailable(
                $documentVersionId,
            );
        }

        $normalized = [];

        foreach ($flags as $flag) {
            if (! is_string($flag)) {
                throw DocumentContextIntegrityException::sourceContentUnavailable(
                    $documentVersionId,
                );
            }

            $normalized[] = $flag;
        }

        $normalized = array_values(
            array_unique($normalized),
        );

        sort($normalized, SORT_STRING);

        return $normalized;
    }
}
