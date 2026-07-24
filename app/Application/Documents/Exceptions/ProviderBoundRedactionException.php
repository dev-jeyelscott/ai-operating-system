<?php

declare(strict_types=1);

namespace App\Application\Documents\Exceptions;

use RuntimeException;

final class ProviderBoundRedactionException extends RuntimeException
{
    public const INVALID_CONFIGURATION =
        'provider_bound_redaction_invalid_configuration';

    public const EXECUTION_FAILED =
        'provider_bound_redaction_execution_failed';

    private function __construct(
        private readonly string $errorCode,
        private readonly string $patternId,
        private readonly ?int $pcreErrorCode,
    ) {
        parent::__construct(
            sprintf(
                'Provider-bound document redaction was blocked (%s).',
                $errorCode,
            ),
        );
    }

    /**
     * Create a safe failure for malformed redaction configuration.
     */
    public static function invalidConfiguration(
        string $patternId,
        ?int $pcreErrorCode = null,
    ): self {
        return new self(
            errorCode: self::INVALID_CONFIGURATION,
            patternId: $patternId,
            pcreErrorCode: $pcreErrorCode,
        );
    }

    /**
     * Create a safe failure when a valid pattern fails during execution.
     */
    public static function executionFailed(
        string $patternId,
        int $pcreErrorCode,
    ): self {
        return new self(
            errorCode: self::EXECUTION_FAILED,
            patternId: $patternId,
            pcreErrorCode: $pcreErrorCode,
        );
    }

    /**
     * Return the stable application error code.
     */
    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * Return the safe configured identifier without exposing the expression.
     */
    public function patternId(): string
    {
        return $this->patternId;
    }

    /**
     * Return the numeric PCRE failure code when available.
     */
    public function pcreErrorCode(): ?int
    {
        return $this->pcreErrorCode;
    }
}
