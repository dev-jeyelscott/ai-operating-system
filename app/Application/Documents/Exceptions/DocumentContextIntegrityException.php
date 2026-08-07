<?php

declare(strict_types=1);

namespace App\Application\Documents\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Reports fail-closed document-context integrity violations.
 *
 * Messages contain identifiers only. Document content, paths, and secrets are
 * intentionally excluded.
 */
final class DocumentContextIntegrityException extends RuntimeException
{
    /**
     * Reject historical snapshots without immutable content artifacts.
     */
    public static function snapshotRequiresUpgrade(
        int $snapshotId,
    ): self {
        return new self(sprintf(
            'Project context snapshot %d does not contain verifiable immutable content artifacts.',
            $snapshotId,
        ));
    }

    /**
     * Reject malformed or ambiguous snapshot identity data.
     */
    public static function malformedSnapshot(
        int $snapshotId,
    ): self {
        return new self(sprintf(
            'Project context snapshot %d contains malformed document identity data.',
            $snapshotId,
        ));
    }

    /**
     * Reject a snapshot whose persisted fingerprint is inconsistent.
     */
    public static function snapshotFingerprintMismatch(
        int $snapshotId,
    ): self {
        return new self(sprintf(
            'Project context snapshot %d failed identity verification.',
            $snapshotId,
        ));
    }

    /**
     * Reject a missing expected document version.
     */
    public static function missingDocumentVersion(
        int $documentVersionId,
    ): self {
        return new self(sprintf(
            'Expected document version %d is unavailable.',
            $documentVersionId,
        ));
    }

    /**
     * Reject changed database evidence for an expected version.
     */
    public static function documentVersionMismatch(
        int $documentVersionId,
        string $field,
    ): self {
        return new self(sprintf(
            'Document version %d failed %s verification.',
            $documentVersionId,
            $field,
        ));
    }

    /**
     * Reject approved source content that cannot be snapshotted.
     */
    public static function sourceContentUnavailable(
        int $documentVersionId,
    ): self {
        return new self(sprintf(
            'Approved document version %d has no valid parsed content.',
            $documentVersionId,
        ));
    }

    /**
     * Reject an immutable artifact that cannot be written safely.
     */
    public static function artifactWriteFailed(
        int $documentVersionId,
        ?Throwable $previous = null,
    ): self {
        return new self(
            sprintf(
                'The immutable content artifact for document version %d could not be stored.',
                $documentVersionId,
            ),
            previous: $previous,
        );
    }

    /**
     * Reject an immutable artifact that cannot be read safely.
     */
    public static function artifactReadFailed(
        int $documentVersionId,
        ?Throwable $previous = null,
    ): self {
        return new self(
            sprintf(
                'The immutable content artifact for document version %d could not be read.',
                $documentVersionId,
            ),
            previous: $previous,
        );
    }

    /**
     * Reject a missing immutable content artifact.
     */
    public static function missingArtifact(
        int $documentVersionId,
    ): self {
        return new self(sprintf(
            'The immutable content artifact for document version %d is missing.',
            $documentVersionId,
        ));
    }

    /**
     * Reject changed or corrupted immutable artifact content.
     */
    public static function artifactChecksumMismatch(
        int $documentVersionId,
    ): self {
        return new self(sprintf(
            'The immutable content artifact for document version %d failed integrity verification.',
            $documentVersionId,
        ));
    }

    /**
     * Reject a theoretical fingerprint collision or inconsistent replay.
     */
    public static function snapshotIdentityConflict(
        int $snapshotId,
    ): self {
        return new self(sprintf(
            'Project context snapshot %d conflicts with the calculated document identity.',
            $snapshotId,
        ));
    }
}
