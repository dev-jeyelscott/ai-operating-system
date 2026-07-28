<?php

declare(strict_types=1);

namespace App\Application\Integrations\Data;

final readonly class NotionPage
{
    /** @param array<string, mixed> $properties */
    public function __construct(
        public string $id,
        public string $dataSourceId,
        public string $url,
        public array $properties,
        public ?string $providerRequestId,
    ) {}
}
