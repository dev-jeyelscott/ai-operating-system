<?php

declare(strict_types=1);

namespace App\Domain\Executions;

use JsonException;
use Throwable;
use TypeError;
use ValueError;

/**
 * Classifies why a provider result was rejected before workflow advancement.
 */
enum ProviderResultRejectionReason: string
{
    case UnsupportedSchema = 'unsupported_schema';
    case MalformedPayload = 'malformed_payload';
    case ContractOrPolicyViolation = 'contract_or_policy_violation';

    /**
     * Convert a low-level provider or validation exception into a stable reason.
     */
    public static function fromThrowable(
        Throwable $exception,
    ): self {
        if (
            $exception instanceof JsonException
            || $exception instanceof TypeError
            || $exception instanceof ValueError
        ) {
            return self::MalformedPayload;
        }

        $message = strtolower($exception->getMessage());

        if (str_contains($message, 'schema version')) {
            return self::UnsupportedSchema;
        }

        if (
            str_contains($message, 'malformed')
            || str_contains($message, 'json')
            || str_contains($message, 'serialization')
        ) {
            return self::MalformedPayload;
        }

        return self::ContractOrPolicyViolation;
    }
}
