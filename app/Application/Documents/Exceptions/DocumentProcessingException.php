<?php

declare(strict_types=1);

namespace App\Application\Documents\Exceptions;

use App\Domain\Documents\DocumentProcessingFailureCode;
use RuntimeException;
use Throwable;

/**
 * Represents a classified document upload or parsing failure.
 */
final class DocumentProcessingException extends RuntimeException
{
    /**
     * Create a classified exception with a safe public message.
     */
    private function __construct(
        public readonly DocumentProcessingFailureCode $failureCode,
        public readonly bool $retryable,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            message: $message,
            code: 0,
            previous: $previous,
        );
    }

    /**
     * Create a deterministic failure that must not be retried.
     */
    public static function permanent(
        DocumentProcessingFailureCode $failureCode,
        string $message,
    ): self {
        return new self(
            failureCode: $failureCode,
            retryable: false,
            message: $message,
        );
    }

    /**
     * Create an infrastructure failure that may succeed after retry.
     */
    public static function retryable(
        DocumentProcessingFailureCode $failureCode,
        string $message,
        ?Throwable $previous = null,
    ): self {
        return new self(
            failureCode: $failureCode,
            retryable: true,
            message: $message,
            previous: $previous,
        );
    }
}
