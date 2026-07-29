<?php

declare(strict_types=1);

namespace App\Application\Development\Data;

final readonly class DevelopmentRepositoryArtifact
{
    public function __construct(
        public string $kind,
        public string $identifier,
        public string $reference,
        public ?string $targetBranch = null,
        public bool $synthetic = true,
        public bool $evidenceStillRequired = true,
    ) {}

    /** @return array{kind:string,identifier:string,reference:string,target_branch:?string,synthetic:bool,evidence_still_required:bool} */
    public function toArray(): array
    {
        return ['kind' => $this->kind, 'identifier' => $this->identifier, 'reference' => $this->reference, 'target_branch' => $this->targetBranch, 'synthetic' => $this->synthetic, 'evidence_still_required' => $this->evidenceStillRequired];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self((string) ($data['kind'] ?? ''), (string) ($data['identifier'] ?? ''), (string) ($data['reference'] ?? ''), isset($data['target_branch']) ? (string) $data['target_branch'] : null, (bool) ($data['synthetic'] ?? false), (bool) ($data['evidence_still_required'] ?? false));
    }
}
