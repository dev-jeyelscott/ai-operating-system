<?php

declare(strict_types=1);

namespace App\Application\Planning\Notion;

final readonly class NotionTicketUpsertResult
{
    public function __construct(
        public string $outcome,
        public int $mappingId,
        public ?string $pageUrl = null,
        public ?string $message = null,
    ) {}
}
