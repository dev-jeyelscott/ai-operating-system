<?php

declare(strict_types=1);

namespace App\Application\Integrations\Exceptions;

use RuntimeException;

/**
 * Indicates that an integration call was rejected before contacting a provider.
 */
final class IntegrationCircuitOpen extends RuntimeException
{
    /**
     * Create a sanitized circuit-open exception.
     */
    public function __construct(
        public readonly string $provider,
        public readonly int $retryAfterSeconds,
    ) {
        parent::__construct(
            sprintf(
                'The %s integration circuit is temporarily open.',
                $provider,
            ),
        );
    }
}
