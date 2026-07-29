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
        foreach (['kind', 'identifier', 'reference'] as $key) {
            if (! array_key_exists($key, $data) || ! is_string($data[$key])) {
                throw new \InvalidArgumentException('Development repository artifact field is malformed.');
            }
        }

        if (! array_key_exists('target_branch', $data) || ($data['target_branch'] !== null && ! is_string($data['target_branch']))
            || ! array_key_exists('synthetic', $data) || ! is_bool($data['synthetic'])
            || ! array_key_exists('evidence_still_required', $data) || ! is_bool($data['evidence_still_required'])) {
            throw new \InvalidArgumentException('Development repository artifact field is malformed.');
        }

        return new self($data['kind'], $data['identifier'], $data['reference'], $data['target_branch'], $data['synthetic'], $data['evidence_still_required']);
    }
}
