<?php

declare(strict_types=1);

namespace App\Domain\Integrations;

/**
 * Defines stable application-owned Notion connection failure categories.
 *
 * Raw provider messages are deliberately excluded because they may contain
 * unstable implementation details or sensitive provider context.
 */
enum NotionConnectionFailureCode: string
{
    case InvalidToken = 'invalid_token';
    case MissingReadCapability = 'missing_read_capability';
    case DatabaseNotShared = 'database_not_shared';
    case WorkspaceMismatch = 'workspace_mismatch';
    case RateLimited = 'rate_limited';
    case ProviderUnavailable = 'provider_unavailable';
    case InvalidProviderResponse = 'invalid_provider_response';
    case ConnectionFailed = 'connection_failed';

    /**
     * Return a safe user-facing remediation message.
     */
    public function userMessage(): string
    {
        return match ($this) {
            self::InvalidToken => 'Notion rejected the credential. Enter a valid integration token and try again.',

            self::MissingReadCapability => 'The Notion connection does not have permission to read the configured database.',

            self::DatabaseNotShared => 'The database was not found or has not been shared with this Notion connection.',

            self::WorkspaceMismatch => 'The credential belongs to a different Notion workspace than the workspace already connected to this project.',

            self::RateLimited => 'Notion is temporarily rate limiting connection tests. Try again shortly.',

            self::ProviderUnavailable => 'Notion is temporarily unavailable. Try the connection test again.',

            self::InvalidProviderResponse => 'Notion returned an unexpected response. Verify the connection configuration and try again.',

            self::ConnectionFailed => 'The Notion connection could not be validated.',
        };
    }
}
