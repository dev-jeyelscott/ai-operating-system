<?php

declare(strict_types=1);

namespace App\Application\Documents;

/**
 * Produces the stable identity of one approved document input set.
 */
final class ApprovedDocumentSetFingerprint
{
    public const SCHEMA_VERSION = 2;

    /**
     * Hash a canonically ordered approved-document set.
     *
     * @param list<array{
     *     document_id: int,
     *     document_version_id: int,
     *     version: int,
     *     checksum_sha256: string,
     *     parsed_content_checksum_sha256: string,
     *     parsed_content_storage_disk: string,
     *     parsed_content_storage_path: string,
     *     analysis_flags: list<string>
     * }> $entries
     */
    public function handle(array $entries): string
    {
        $canonicalEntries = array_map(
            static fn (array $entry): array => [
                'document_id' => $entry['document_id'],
                'document_version_id' => $entry['document_version_id'],
                'version' => $entry['version'],
                'checksum_sha256' => $entry['checksum_sha256'],
                'parsed_content_checksum_sha256' => $entry['parsed_content_checksum_sha256'],
                'parsed_content_storage_disk' => $entry['parsed_content_storage_disk'],
                'parsed_content_storage_path' => $entry['parsed_content_storage_path'],
                'analysis_flags' => $entry['analysis_flags'],
            ],
            $entries,
        );

        usort(
            $canonicalEntries,
            static fn (array $left, array $right): int => [
                $left['document_id'],
                $left['version'],
                $left['document_version_id'],
            ] <=> [
                $right['document_id'],
                $right['version'],
                $right['document_version_id'],
            ],
        );

        return hash(
            'sha256',
            json_encode(
                [
                    'schema_version' => self::SCHEMA_VERSION,
                    'documents' => $canonicalEntries,
                ],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ),
        );
    }
}
