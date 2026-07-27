<?php

declare(strict_types=1);

namespace App\Application\Planning\Data;

final readonly class PlanningDocument
{
    public function __construct(
        public PlanningSourceReference $source,
        public string $classification,
        public string $summary,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            ...$this->source->toArray(),
            'classification' => $this->classification,
            'summary' => $this->summary,
        ];
    }
}
