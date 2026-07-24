<?php

declare(strict_types=1);

namespace App\Application\Documents;

use App\Application\Documents\Exceptions\DocumentContextIntegrityException;
use App\Models\DocumentVersion;
use App\Models\ProjectContextSnapshot;
use Illuminate\Support\Facades\Storage;
use JsonException;
use Throwable;

/**
 * Builds the only document payload permitted to leave the application.
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
 * @phpstan-type ProviderContextEntry array{
 *     document_version_id: int,
 *     checksum_sha256: string,
 *     content: string,
 *     safety_flags: list<string>
 * }
 */
final readonly class BuildProviderBoundDocumentContext
{
    public function __construct(
        private RedactProviderBoundDocumentContext $redactor,
        private ApprovedDocumentSetFingerprint $fingerprint,
    ) {}

    /**
     * Reconstruct and verify immutable document payloads before redaction.
     *
     * @return list<ProviderContextEntry>
     */
    public function handle(
        ProjectContextSnapshot $snapshot,
    ): array {
        if (
            $snapshot->identity_schema_version
            !== ApprovedDocumentSetFingerprint::SCHEMA_VERSION
        ) {
            throw DocumentContextIntegrityException::snapshotRequiresUpgrade(
                $snapshot->id,
            );
        }

        $entries = $this->validatedEntries($snapshot);

        if (
            ! hash_equals(
                $snapshot->approved_document_set_fingerprint,
                $this->fingerprint->handle($entries),
            )
        ) {
            throw DocumentContextIntegrityException::snapshotFingerprintMismatch(
                $snapshot->id,
            );
        }

        if ($entries === []) {
            return [];
        }

        /** @var array<int, SnapshotEntry> $expectedByVersionId */
        $expectedByVersionId = [];

        foreach ($entries as $entry) {
            $expectedByVersionId[$entry['document_version_id']] = $entry;
        }

        $versions = DocumentVersion::query()
            ->whereIn('id', array_keys($expectedByVersionId))
            ->whereHas(
                'document',
                fn ($query) => $query->where(
                    'project_id',
                    $snapshot->project_id,
                ),
            )
            ->get()
            ->keyBy('id');

        if ($versions->count() !== count($expectedByVersionId)) {
            foreach (array_keys($expectedByVersionId) as $documentVersionId) {
                if (! $versions->has($documentVersionId)) {
                    throw DocumentContextIntegrityException::missingDocumentVersion(
                        $documentVersionId,
                    );
                }
            }

            throw DocumentContextIntegrityException::malformedSnapshot(
                $snapshot->id,
            );
        }

        $context = [];

        foreach ($entries as $entry) {
            $documentVersionId = $entry['document_version_id'];
            $version = $versions->get($documentVersionId);

            if (! $version instanceof DocumentVersion) {
                throw DocumentContextIntegrityException::missingDocumentVersion(
                    $documentVersionId,
                );
            }

            $this->verifyDatabaseEvidence(
                version: $version,
                expected: $entry,
            );

            $artifactContent = $this->verifiedArtifactContent(
                versionId: $documentVersionId,
                disk: $entry['parsed_content_storage_disk'],
                path: $entry['parsed_content_storage_path'],
                checksum: $entry['parsed_content_checksum_sha256'],
            );

            $context[] = [
                'document_version_id' => $documentVersionId,
                'checksum_sha256' => $entry['checksum_sha256'],
                'content' => $this->redactor->handle($artifactContent),
                'safety_flags' => $entry['analysis_flags'],
            ];
        }

        return $context;
    }

    /**
     * Decode and validate the raw JSON attribute instead of trusting its cast.
     *
     * Reading the raw value is intentional: the model PHPDoc describes the
     * expected post-validation shape, while this boundary must still fail
     * closed when persisted JSON is malformed or tampered with.
     *
     * @return list<SnapshotEntry>
     */
    private function validatedEntries(
        ProjectContextSnapshot $snapshot,
    ): array {
        $rawEntries = $snapshot->getRawOriginal(
            'approved_document_versions',
        );

        if (is_string($rawEntries)) {
            try {
                $rawEntries = json_decode(
                    $rawEntries,
                    true,
                    512,
                    JSON_THROW_ON_ERROR,
                );
            } catch (JsonException) {
                throw DocumentContextIntegrityException::malformedSnapshot(
                    $snapshot->id,
                );
            }
        }

        if (! is_array($rawEntries) || ! array_is_list($rawEntries)) {
            throw DocumentContextIntegrityException::malformedSnapshot(
                $snapshot->id,
            );
        }

        $validated = [];
        $seenVersionIds = [];

        foreach ($rawEntries as $rawEntry) {
            $entry = $this->validatedEntry(
                rawEntry: $rawEntry,
                snapshotId: $snapshot->id,
            );

            $documentVersionId = $entry['document_version_id'];

            if (isset($seenVersionIds[$documentVersionId])) {
                throw DocumentContextIntegrityException::malformedSnapshot(
                    $snapshot->id,
                );
            }

            $seenVersionIds[$documentVersionId] = true;
            $validated[] = $entry;
        }

        return $validated;
    }

    /**
     * Validate one persisted snapshot entry and return its canonical shape.
     *
     * @return SnapshotEntry
     */
    private function validatedEntry(
        mixed $rawEntry,
        int $snapshotId,
    ): array {
        if (! is_array($rawEntry)) {
            throw DocumentContextIntegrityException::malformedSnapshot(
                $snapshotId,
            );
        }

        $requiredKeys = [
            'document_id',
            'document_version_id',
            'version',
            'checksum_sha256',
            'parsed_content_checksum_sha256',
            'parsed_content_storage_disk',
            'parsed_content_storage_path',
            'analysis_flags',
        ];

        foreach ($requiredKeys as $requiredKey) {
            if (! array_key_exists($requiredKey, $rawEntry)) {
                throw DocumentContextIntegrityException::malformedSnapshot(
                    $snapshotId,
                );
            }
        }

        $documentId = $rawEntry['document_id'];
        $documentVersionId = $rawEntry['document_version_id'];
        $version = $rawEntry['version'];
        $checksum = $rawEntry['checksum_sha256'];
        $parsedContentChecksum =
            $rawEntry['parsed_content_checksum_sha256'];
        $artifactDisk = $rawEntry['parsed_content_storage_disk'];
        $artifactPath = $rawEntry['parsed_content_storage_path'];
        $rawFlags = $rawEntry['analysis_flags'];

        if (
            ! is_int($documentId)
            || $documentId < 1
            || ! is_int($documentVersionId)
            || $documentVersionId < 1
            || ! is_int($version)
            || $version < 1
            || ! $this->isSha256($checksum)
            || ! $this->isSha256($parsedContentChecksum)
            || ! is_string($artifactDisk)
            || trim($artifactDisk) === ''
            || ! is_string($artifactPath)
            || trim($artifactPath) === ''
            || ! is_array($rawFlags)
            || ! array_is_list($rawFlags)
        ) {
            throw DocumentContextIntegrityException::malformedSnapshot(
                $snapshotId,
            );
        }

        $flags = [];

        foreach ($rawFlags as $rawFlag) {
            if (! is_string($rawFlag)) {
                throw DocumentContextIntegrityException::malformedSnapshot(
                    $snapshotId,
                );
            }

            $flags[] = $rawFlag;
        }

        $flags = array_values(array_unique($flags));
        sort($flags, SORT_STRING);

        return [
            'document_id' => $documentId,
            'document_version_id' => $documentVersionId,
            'version' => $version,
            'checksum_sha256' => $checksum,
            'parsed_content_checksum_sha256' => $parsedContentChecksum,
            'parsed_content_storage_disk' => $artifactDisk,
            'parsed_content_storage_path' => $artifactPath,
            'analysis_flags' => $flags,
        ];
    }

    /**
     * Verify current rows against the exact evidence frozen in the snapshot.
     *
     * Database content is not used as the provider payload. It is checked only
     * as an additional drift detector before the immutable artifact is loaded.
     *
     * @param  SnapshotEntry  $expected
     */
    private function verifyDatabaseEvidence(
        DocumentVersion $version,
        array $expected,
    ): void {
        if ((int) $version->document_id !== $expected['document_id']) {
            throw DocumentContextIntegrityException::documentVersionMismatch(
                $version->id,
                'document identity',
            );
        }

        if ((int) $version->version !== $expected['version']) {
            throw DocumentContextIntegrityException::documentVersionMismatch(
                $version->id,
                'version identity',
            );
        }

        if (
            ! hash_equals(
                $expected['checksum_sha256'],
                (string) $version->checksum_sha256,
            )
        ) {
            throw DocumentContextIntegrityException::documentVersionMismatch(
                $version->id,
                'file checksum',
            );
        }

        $parsedContent = $version->parsed_content;

        if (! is_string($parsedContent)) {
            throw DocumentContextIntegrityException::documentVersionMismatch(
                $version->id,
                'parsed content',
            );
        }

        if (
            ! hash_equals(
                $expected['parsed_content_checksum_sha256'],
                hash('sha256', $parsedContent),
            )
        ) {
            throw DocumentContextIntegrityException::documentVersionMismatch(
                $version->id,
                'parsed content checksum',
            );
        }

        $currentFlags = $version->analysis_flags;

        if ($currentFlags === null) {
            throw DocumentContextIntegrityException::documentVersionMismatch(
                $version->id,
                'safety flags',
            );
        }

        $currentFlags = array_values(array_unique($currentFlags));
        sort($currentFlags, SORT_STRING);

        if ($currentFlags !== $expected['analysis_flags']) {
            throw DocumentContextIntegrityException::documentVersionMismatch(
                $version->id,
                'safety flags',
            );
        }
    }

    /**
     * Read and verify the immutable parsed-content artifact.
     */
    private function verifiedArtifactContent(
        int $versionId,
        string $disk,
        string $path,
        string $checksum,
    ): string {
        try {
            $filesystem = Storage::disk($disk);

            if ($filesystem->missing($path)) {
                throw DocumentContextIntegrityException::missingArtifact(
                    $versionId,
                );
            }

            $content = $filesystem->get($path);
        } catch (DocumentContextIntegrityException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw DocumentContextIntegrityException::artifactReadFailed(
                $versionId,
                $exception,
            );
        }

        if (! hash_equals($checksum, hash('sha256', $content))) {
            throw DocumentContextIntegrityException::artifactChecksumMismatch(
                $versionId,
            );
        }

        return $content;
    }

    /**
     * Determine whether a raw value is a lowercase SHA-256 digest.
     */
    private function isSha256(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/\A[0-9a-f]{64}\z/D', $value) === 1;
    }
}
