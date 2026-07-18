<?php

declare(strict_types=1);

namespace App\Application\Identity\Data;

use App\Domain\Identity\OrganizationRole;

/**
 * Transport-safe representation of an organization membership.
 */
final readonly class OrganizationMembershipData
{
    /**
     * Create an immutable membership result.
     */
    public function __construct(
        public int $id,
        public int $organizationId,
        public int $userId,
        public OrganizationRole $role,
    ) {}
}
