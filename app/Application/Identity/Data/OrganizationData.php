<?php

declare(strict_types=1);

namespace App\Application\Identity\Data;

/**
 * Transport-safe result returned after creating an organization.
 */
final readonly class OrganizationData
{
    /**
     * Create an immutable organization result.
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $slug,
    ) {}
}
