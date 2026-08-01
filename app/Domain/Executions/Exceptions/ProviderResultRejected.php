<?php

declare(strict_types=1);

namespace App\Domain\Executions\Exceptions;

use App\Domain\Executions\ProviderResultRejectionReason;
use InvalidArgumentException;
use Throwable;

/**
 * Indicates that a provider result failed deterministic validation.
 *
 * This exception intentionally extends InvalidArgumentException so the
 * existing planning, development, and QA invalid-result handling continues
 * to block or fail the current execution without scheduling unsafe retries.
 */
final class ProviderResultRejected extends InvalidArgumentException
{
    /**
     * Create a provider-result rejection with safe structured context.
     */
    public function __construct(
        public readonly string $providerId,
        public readonly string $capability,
        public readonly ProviderResultRejectionReason $reason,
        public readonly ?int $resultSchemaVersion,
        Throwable $previous,
    ) {
        parent::__construct(
            message: sprintf(
                'Provider result rejected [%s] for capability [%s] from provider [%s].',
                $reason->value,
                $capability,
                $providerId,
            ),
            code: 0,
            previous: $previous,
        );
    }

    /**
     * Translate a provider or validator exception into a stable rejection.
     */
    public static function fromThrowable(
        string $providerId,
        string $capability,
        ?int $resultSchemaVersion,
        Throwable $exception,
    ): self {
        return new self(
            providerId: $providerId,
            capability: $capability,
            reason: ProviderResultRejectionReason::fromThrowable(
                $exception,
            ),
            resultSchemaVersion: $resultSchemaVersion,
            previous: $exception,
        );
    }
}
