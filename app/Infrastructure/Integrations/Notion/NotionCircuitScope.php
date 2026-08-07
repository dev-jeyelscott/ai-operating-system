<?php

declare(strict_types=1);

namespace App\Infrastructure\Integrations\Notion;

use App\Domain\Integrations\IntegrationCredentialSecret;
use LogicException;

/**
 * Generates irreversible circuit scopes without exposing provider credentials.
 */
final class NotionCircuitScope
{
    /**
     * Build a stable credential and operation-specific circuit scope.
     */
    public function forCredential(
        IntegrationCredentialSecret $credential,
        string $channel,
    ): string {
        $applicationKey = config('app.key');

        if (
            ! is_string($applicationKey)
            || trim($applicationKey) === ''
        ) {
            throw new LogicException(
                'APP_KEY is required to derive integration circuit scopes.',
            );
        }

        return hash_hmac(
            'sha256',
            $channel.'|'.$credential->reveal(),
            $applicationKey,
        );
    }
}
