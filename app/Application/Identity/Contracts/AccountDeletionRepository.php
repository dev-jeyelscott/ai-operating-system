<?php

declare(strict_types=1);

namespace App\Application\Identity\Contracts;

use App\Application\Identity\Data\OrganizationMembershipData;

/**
 * Defines the persistence and locking operations required for account deletion.
 */
interface AccountDeletionRepository
{
    /**
     * Lock the user row for the lifetime of the current transaction.
     */
    public function lockUser(int $userId): bool;

    /**
     * Return organization IDs associated with the user in deterministic order.
     *
     * @return list<int>
     */
    public function organizationIdsForUser(int $userId): array;

    /**
     * Lock organizations in deterministic order.
     *
     * Organization rows are the serialization boundary for operations that may
     * change organization ownership.
     *
     * @param  list<int>  $organizationIds
     */
    public function lockOrganizations(array $organizationIds): void;

    /**
     * Lock the user's memberships and all owner memberships for the supplied
     * organizations.
     *
     * @param  list<int>  $organizationIds
     * @return list<OrganizationMembershipData>
     */
    public function lockDeletionMemberships(
        int $userId,
        array $organizationIds,
    ): array;

    /**
     * Delete every organization membership belonging to the user.
     */
    public function deleteMembershipsForUser(int $userId): void;

    /**
     * Delete the user and report whether exactly one account was removed.
     */
    public function deleteUser(int $userId): bool;
}
