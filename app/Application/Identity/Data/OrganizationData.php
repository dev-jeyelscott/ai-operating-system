<?php

declare(strict_types=1);

namespace App\Application\Identity\Data;

/**
 * Transport-safe organization result.
 */
final readonly class OrganizationData
{
    /**
     * Create an immutable organization result.
     *
     * ownerMembershipId is populated by organization creation and remains null
     * for organization-list query results.
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $slug,
        public ?int $ownerMembershipId = null,
    ) {}
}
