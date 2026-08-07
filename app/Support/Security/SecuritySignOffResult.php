<?php

declare(strict_types=1);

namespace App\Support\Security;

/**
 * Represents the deterministic result of validating the security sign-off.
 */
final readonly class SecuritySignOffResult
{
    /**
     * Create an immutable security sign-off result.
     *
     * @param  list<string>  $violations
     */
    public function __construct(
        public string $decision,
        public string $reviewedCommit,
        public int $dependencyCount,
        public int $findingCount,
        public array $violations,
    ) {}

    /**
     * Determine whether the release security gate has passed.
     */
    public function passed(): bool
    {
        return $this->decision === 'approved'
            && $this->violations === [];
    }
}
