<?php

declare(strict_types=1);

namespace App\Domain\Integrations;

use App\Domain\Integrations\Exceptions\InvalidIntegrationCredential;

/**
 * Validated plaintext integration credential.
 *
 * The value is intentionally private and is redacted from debug output.
 */
final readonly class IntegrationCredentialSecret
{
    public const MIN_LENGTH = 20;

    public const MAX_LENGTH = 4096;

    private const CONTROL_CHARACTER_PATTERN = '/[\x00-\x1F\x7F]/';

    private string $value;

    /**
     * Store a previously validated credential.
     */
    private function __construct(
        #[\SensitiveParameter]
        string $value,
    ) {
        $this->value = $value;
    }

    /**
     * Validate and create an integration credential.
     */
    public static function from(
        #[\SensitiveParameter]
        string $value,
    ): self {
        $length = strlen($value);

        if ($length < self::MIN_LENGTH) {
            throw new InvalidIntegrationCredential(sprintf(
                'The integration credential must contain at least %d characters.',
                self::MIN_LENGTH,
            ));
        }

        if ($length > self::MAX_LENGTH) {
            throw new InvalidIntegrationCredential(sprintf(
                'The integration credential may not exceed %d characters.',
                self::MAX_LENGTH,
            ));
        }

        /*
         * Do not silently trim credentials. A leading or trailing space usually
         * indicates an accidental copy-and-paste error and could produce a token
         * that appears valid while failing at the provider.
         */
        if (trim($value) !== $value) {
            throw new InvalidIntegrationCredential(
                'The integration credential must not contain leading or trailing whitespace.',
            );
        }

        if (preg_match(self::CONTROL_CHARACTER_PATTERN, $value) === 1) {
            throw new InvalidIntegrationCredential(
                'The integration credential must not contain control characters.',
            );
        }

        return new self($value);
    }

    /**
     * Return the plaintext only to the explicit encryption/provider boundary.
     *
     * Callers must never log, serialize, audit, flash, or return this value.
     */
    public function reveal(): string
    {
        return $this->value;
    }

    /**
     * Compare two credentials without using normal string equality.
     */
    public function equals(self $other): bool
    {
        return hash_equals($this->value, $other->value);
    }

    /**
     * Prevent debuggers and accidental dumps from displaying the credential.
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return [
            'value' => '[REDACTED]',
        ];
    }
}
