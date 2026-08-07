<?php

declare(strict_types=1);

namespace App\Application\Development\Data;

use App\Domain\Development\DevelopmentStage;
use App\Domain\Development\DevelopmentStageStatus;

final readonly class DevelopmentStageResult
{
    public function __construct(public DevelopmentStage $stage, public DevelopmentStageStatus $status, public string $summary) {}

    /** @return array{stage:string,status:string,summary:string} */
    public function toArray(): array
    {
        return ['stage' => $this->stage->value, 'status' => $this->status->value, 'summary' => $this->summary];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        if (! isset($data['stage'], $data['status'], $data['summary'])
            || ! is_string($data['stage']) || ! is_string($data['status']) || ! is_string($data['summary'])) {
            throw new \InvalidArgumentException('Development stage result field is malformed.');
        }

        return new self(DevelopmentStage::from($data['stage']), DevelopmentStageStatus::from($data['status']), $data['summary']);
    }
}
