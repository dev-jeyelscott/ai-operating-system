<?php

declare(strict_types=1);

namespace App\Application\Projects;

use App\Application\Projects\Data\StartProjectPreflightResult;
use App\Models\ProjectContextSnapshot;
use InvalidArgumentException;

/**
 * Produces the stable, non-secret identity of StartProject context inputs.
 *
 * The fingerprint intentionally contains only immutable identifiers and
 * checksums. It never contains parsed document content or provider secrets.
 */
final class StartProjectContextFingerprint
{
    private const SCHEMA_VERSION = 1;

    /**
     * Build the fingerprint returned when preparing a StartProject command.
     */
    public function fromPreflight(
        StartProjectPreflightResult $preflight,
    ): string {
        $configurationVersionId =
            $preflight->contextSnapshot['configuration_version_id']
            ?? null;

        $configurationRevision =
            $preflight->contextSnapshot['configuration_version_revision'] ?? null;

        $approvedVersions =
            $preflight->documents['approved_versions'] ?? [];

        return $this->hash(
            configurationVersionId: $this->nullableInteger(
                $configurationVersionId,
                'configuration version identifier',
            ),
            configurationRevision: $this->nullableInteger(
                $configurationRevision,
                'configuration revision',
            ),
            documents: $this->normalizeDocuments(
                $approvedVersions,
            ),
        );
    }

    /**
     * Reconstruct the fingerprint from an immutable persisted snapshot.
     */
    public function fromSnapshot(
        ProjectContextSnapshot $snapshot,
    ): string {
        return $this->hash(
            configurationVersionId: (int) $snapshot->project_configuration_version_id,
            configurationRevision: (int) $snapshot->configuration_revision,
            documents: $this->normalizeDocuments(
                $snapshot->approved_document_versions,
            ),
        );
    }

    /**
     * Hash one canonical configuration and document-version identity.
     *
     * @param  list<array{
     *     document_id: int,
     *     document_version_id: int,
     *     version: int,
     *     checksum_sha256: string
     * }>  $documents
     */
    private function hash(
        ?int $configurationVersionId,
        ?int $configurationRevision,
        array $documents,
    ): string {
        return hash(
            'sha256',
            json_encode(
                [
                    'schema_version' => self::SCHEMA_VERSION,
                    'configuration_version_id' => $configurationVersionId,
                    'configuration_revision' => $configurationRevision,
                    'documents' => $documents,
                ],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ),
        );
    }

    /**
     * Retain only immutable fields required to identify the document set.
     *
     * @return list<array{
     *     document_id: int,
     *     document_version_id: int,
     *     version: int,
     *     checksum_sha256: string
     * }>
     */
    private function normalizeDocuments(
        mixed $documents,
    ): array {
        if (! is_array($documents)) {
            throw new InvalidArgumentException(
                'StartProject document context must be an array.',
            );
        }

        $normalized = [];

        foreach ($documents as $document) {
            if (! is_array($document)) {
                throw new InvalidArgumentException(
                    'StartProject document context contains an invalid entry.',
                );
            }

            $checksum = $document['checksum_sha256'] ?? null;

            if (
                ! is_string($checksum)
                || preg_match('/\A[a-f0-9]{64}\z/', $checksum) !== 1
            ) {
                throw new InvalidArgumentException(
                    'StartProject document context contains an invalid checksum.',
                );
            }

            $normalized[] = [
                'document_id' => $this->positiveInteger(
                    $document['document_id'] ?? null,
                    'document identifier',
                ),
                'document_version_id' => $this->positiveInteger(
                    $document['document_version_id'] ?? null,
                    'document version identifier',
                ),
                'version' => $this->positiveInteger(
                    $document['version'] ?? null,
                    'document version',
                ),
                'checksum_sha256' => $checksum,
            ];
        }

        usort(
            $normalized,
            static fn (
                array $left,
                array $right,
            ): int => [
                $left['document_id'],
                $left['version'],
                $left['document_version_id'],
            ] <=> [
                $right['document_id'],
                $right['version'],
                $right['document_version_id'],
            ],
        );

        return $normalized;
    }

    /**
     * Normalize one optional positive integer.
     */
    private function nullableInteger(
        mixed $value,
        string $name,
    ): ?int {
        if ($value === null) {
            return null;
        }

        return $this->positiveInteger(
            value: $value,
            name: $name,
        );
    }

    /**
     * Normalize one required positive integer.
     */
    private function positiveInteger(
        mixed $value,
        string $name,
    ): int {
        if (! is_int($value) || $value < 1) {
            throw new InvalidArgumentException(
                "The StartProject {$name} must be a positive integer.",
            );
        }

        return $value;
    }
}
