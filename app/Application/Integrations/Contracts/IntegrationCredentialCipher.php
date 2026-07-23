<?php

declare(strict_types=1);

namespace App\Application\Integrations\Contracts;

use App\Domain\Integrations\IntegrationCredentialSecret;

/**
 * Encrypts and decrypts integration credentials through a replaceable boundary.
 */
interface IntegrationCredentialCipher
{
    /**
     * Encrypt a validated plaintext credential for durable storage.
     */
    public function encrypt(IntegrationCredentialSecret $credential): string;

    /**
     * Decrypt stored ciphertext for an authorized provider operation.
     */
    public function decrypt(
        #[\SensitiveParameter]
        string $ciphertext,
    ): IntegrationCredentialSecret;
}
