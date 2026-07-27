<?php

declare(strict_types=1);

namespace App\Application\Planning\Data;

final readonly class PlanningAcceptanceCriterion
{
    /** @param list<PlanningSourceReference> $sourceReferences */
    public function __construct(
        public string $stableId,
        public string $description,
        public array $sourceReferences,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'stable_id' => $this->stableId,
            'description' => $this->description,
            'source_references' => array_map(
                static fn (PlanningSourceReference $reference): array => $reference->toArray(),
                $this->sourceReferences,
            ),
        ];
    }
}
