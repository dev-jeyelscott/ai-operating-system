<?php

declare(strict_types=1);

namespace App\Application\Development\Data;

use App\Domain\Development\DevelopmentChangeType;

final readonly class DevelopmentChangedFile
{
    public function __construct(public string $path, public DevelopmentChangeType $changeType, public string $summary) {}

    /** @return array{path:string,change_type:string,summary:string} */
    public function toArray(): array
    {
        return ['path' => $this->path, 'change_type' => $this->changeType->value, 'summary' => $this->summary];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self((string) ($data['path'] ?? ''), DevelopmentChangeType::from((string) ($data['change_type'] ?? '')), (string) ($data['summary'] ?? ''));
    }
}
