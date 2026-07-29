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
        return new self((string) ($data['command'] ?? ''), DevelopmentValidationStatus::from((string) ($data['status'] ?? '')), (string) ($data['summary'] ?? ''));
    }
}
