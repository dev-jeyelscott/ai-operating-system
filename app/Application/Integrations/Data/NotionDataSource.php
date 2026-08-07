<?php

declare(strict_types=1);

namespace App\Application\Integrations\Data;

final readonly class NotionDataSource
{
    /** @param array<string, array<string, mixed>> $properties */
    public function __construct(
        public string $id,
        public ?string $name,
        public array $properties,
        public ?string $providerRequestId,
    ) {}
}
