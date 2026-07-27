<?php

declare(strict_types=1);

namespace App\Application\Planning\Data;

final readonly class PlanningSourceReference
{
    public function __construct(
        public int $documentId,
        public int $documentVersionId,
        public int $version,
        public string $checksumSha256,
    ) {}

    /** @return array{document_id:int,document_version_id:int,version:int,checksum_sha256:string} */
    public function toArray(): array
    {
        return [
            'document_id' => $this->documentId,
            'document_version_id' => $this->documentVersionId,
            'version' => $this->version,
            'checksum_sha256' => $this->checksumSha256,
        ];
    }
}
