<?php

declare(strict_types=1);

namespace App\Application\Development\Data;

use App\Domain\Development\DevelopmentValidationStatus;

final readonly class DevelopmentValidationResult
{
    public function __construct(public string $command, public DevelopmentValidationStatus $status, public string $summary) {}

    /** @return array{command:string,status:string,summary:string} */
    public function toArray(): array
    {
        return ['command' => $this->command, 'status' => $this->status->value, 'summary' => $this->summary];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        if (! isset($data['command'], $data['status'], $data['summary'])
            || ! is_string($data['command']) || ! is_string($data['status']) || ! is_string($data['summary'])) {
            throw new \InvalidArgumentException('Development validation result field is malformed.');
        }

        return new self($data['command'], DevelopmentValidationStatus::from($data['status']), $data['summary']);
    }
}
