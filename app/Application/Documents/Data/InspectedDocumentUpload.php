<?php

declare(strict_types=1);

namespace App\Application\Documents\Data;

/**
 * Contains server-derived metadata for a document that passed inspection.
 */
final readonly class InspectedDocumentUpload
{
    /**
     * Create immutable metadata for one accepted upload.
     */
    public function __construct(
        public string $originalFilename,
        public string $mediaType,
        public int $byteSize,
        public string $realPath,
        public string $checksumSha256,
    ) {}
}
