<?php

declare(strict_types=1);

namespace App\Infrastructure\Integrations;

use App\Application\Integrations\Contracts\IntegrationCredentialCipher;
use App\Domain\Integrations\IntegrationCredentialSecret;
use Illuminate\Contracts\Encryption\Encrypter;

/**
 * Uses Laravel's authenticated application encryption service.
 */
final readonly class LaravelIntegrationCredentialCipher implements IntegrationCredentialCipher
{
    /**
     * Inject Laravel's configured encrypter.
     */
    public function __construct(
        private Encrypter $encrypter,
    ) {}

    /**
     * Encrypt a credential using the current application encryption key.
     */
    public function encrypt(IntegrationCredentialSecret $credential): string
    {
        return $this->encrypter->encryptString(
            $credential->reveal(),
        );
    }

    /**
     * Decrypt ciphertext using the current or configured previous keys.
     */
    public function decrypt(
        #[\SensitiveParameter]
        string $ciphertext,
    ): IntegrationCredentialSecret {
        return IntegrationCredentialSecret::from(
            $this->encrypter->decryptString($ciphertext),
        );
    }
}
