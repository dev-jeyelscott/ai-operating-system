<?php

declare(strict_types=1);

namespace App\Application\Identity\Contracts;

use App\Application\Identity\Data\OrganizationData;
use App\Application\Identity\Data\OrganizationMembershipData;
use App\Domain\Identity\OrganizationRole;

/**
 * Defines persistence operations required by Identity application use cases.
 *
 * Concrete Eloquent behavior belongs in the infrastructure layer.
 */
interface OrganizationRepository
{
    /**
     * Create an organization and its initial owner atomically.
     */
    public function createWithOwner(
        int $ownerUserId,
        string $name,
    ): OrganizationData;

    /**
     * Add a user to an organization with an explicit role.
     */
    public function addMember(
        int $organizationId,
        int $userId,
        OrganizationRole $role,
    ): OrganizationMembershipData;

    /**
     * List every organization available to the user in stable display order.
     *
     * @return list<OrganizationData>
     */
    public function listForUser(int $userId): array;
}
