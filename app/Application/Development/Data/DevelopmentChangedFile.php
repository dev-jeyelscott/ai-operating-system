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
        if (! isset($data['path'], $data['change_type'], $data['summary'])
            || ! is_string($data['path']) || ! is_string($data['change_type']) || ! is_string($data['summary'])) {
            throw new \InvalidArgumentException('Development changed-file field is malformed.');
        }

        return new self($data['path'], DevelopmentChangeType::from($data['change_type']), $data['summary']);
    }
}
