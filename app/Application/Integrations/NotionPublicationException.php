<?php

declare(strict_types=1);

namespace App\Application\Integrations;

use RuntimeException;

/** Carries only sanitized provider diagnostics. */
final class NotionPublicationException extends RuntimeException
{
    public function __construct(
        public readonly string $category,
        public readonly bool $retryable,
        public readonly ?string $providerRequestId = null,
    ) {
        parent::__construct('Notion publication request failed: '.$category);
    }
}
