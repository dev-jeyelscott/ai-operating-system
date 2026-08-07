<?php

declare(strict_types=1);

namespace App\Application\Codex\Data;

use InvalidArgumentException;

/**
 * Identifies one local OS process strongly enough to guard against PID reuse.
 */
final readonly class CodexRuntimeIdentity
{
    /**
     * Create a validated process identity.
     */
    public function __construct(
        public string $hostId,
        public string $fingerprint,
    ) {
        if (trim($this->hostId) === '') {
            throw new InvalidArgumentException(
                'Codex runtime host identity cannot be empty.',
            );
        }

        if (
            preg_match(
                '/\A[a-f0-9]{64}\z/D',
                $this->fingerprint,
            ) !== 1
        ) {
            throw new InvalidArgumentException(
                'Codex runtime identity fingerprint must be SHA-256.',
            );
        }
    }
}
