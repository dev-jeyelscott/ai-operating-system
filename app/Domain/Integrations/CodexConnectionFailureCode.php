<?php

declare(strict_types=1);

namespace App\Domain\Integrations;

/**
 * Defines normalized Codex preflight failures that are safe to persist/display.
 *
 * Raw provider error bodies and exception messages must never be persisted.
 */
enum CodexConnectionFailureCode: string
{
    case InvalidCredential = 'invalid_credential';
    case MissingCapability = 'missing_capability';
    case ModelUnavailable = 'model_unavailable';
    case RateLimited = 'rate_limited';
    case ProviderUnavailable = 'provider_unavailable';
    case InvalidProviderResponse = 'invalid_provider_response';
    case ConnectionFailed = 'connection_failed';

    /**
     * Return whether this failure proves the stored credential/policy is no
     * longer safe to treat as verified for real-provider selection.
     */
    public function invalidatesVerification(): bool
    {
        return match ($this) {
            self::RateLimited,
            self::ProviderUnavailable => false,

            self::InvalidCredential,
            self::MissingCapability,
            self::ModelUnavailable,
            self::InvalidProviderResponse,
            self::ConnectionFailed => true,
        };
    }

    /**
     * Return a normalized user-facing message without provider-controlled text.
     */
    public function userMessage(): string
    {
        return match ($this) {
            self::InvalidCredential => 'Codex rejected the configured credential.',
            self::MissingCapability => 'The configured credential does not have the required Codex access.',
            self::ModelUnavailable => 'The configured Codex model is not available to this credential.',
            self::RateLimited => 'Codex rate-limited the connection check. Try again later.',
            self::ProviderUnavailable => 'Codex is temporarily unavailable. Try again later.',
            self::InvalidProviderResponse => 'Codex returned an invalid connection-check response.',
            self::ConnectionFailed => 'The Codex connection check could not be completed.',
        };
    }
}
