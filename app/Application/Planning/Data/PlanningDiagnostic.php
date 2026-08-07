<?php

declare(strict_types=1);

namespace App\Application\Planning\Data;

final readonly class PlanningDiagnostic
{
    /** @param array<string, scalar|list<scalar>|null> $details */
    public function __construct(
        public string $code,
        public string $category,
        public string $message,
        public array $details = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'category' => $this->category,
            'message' => $this->message,
            'details' => $this->details,
        ];
    }
}
