<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repositories\Identity;

use App\Application\Identity\Contracts\AccountDeletionRepository;
use App\Application\Identity\Data\OrganizationMembershipData;
use App\Domain\Identity\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;

/**
 * Implements transactional account-deletion persistence using Eloquent.
 */
final class EloquentAccountDeletionRepository implements AccountDeletionRepository
{
    /**
     * Lock the user to prevent concurrent deletion or membership insertion.
     */
    public function lockUser(int $userId): bool
    {
        return User::query()
            ->whereKey($userId)
            ->lockForUpdate()
            ->first(['id']) instanceof User;
    }

    /**
     * Return all organizations associated with the user in stable order.
     *
     * @return list<int>
     */
    public function organizationIdsForUser(int $userId): array
    {
        return array_values(
            OrganizationMembership::query()
                ->where('user_id', $userId)
                ->orderBy('organization_id')
                ->orderBy('id')
                ->get(['organization_id'])
                ->map(
                    static fn (
                        OrganizationMembership $membership,
                    ): int => $membership->organization_id,
                )
                ->unique()
                ->values()
                ->all(),
        );
    }

    /**
     * Lock organization rows in ascending order.
     *
     * @param  list<int>  $organizationIds
     */
    public function lockOrganizations(array $organizationIds): void
    {
        $organizationIds = $this->normalizeOrganizationIds(
            $organizationIds,
        );

        if ($organizationIds === []) {
            return;
        }

        Organization::query()
            ->whereKey($organizationIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id']);
    }

    /**
     * Lock the target user's memberships and all owner memberships.
     *
     * @param  list<int>  $organizationIds
     * @return list<OrganizationMembershipData>
     */
    public function lockDeletionMemberships(
        int $userId,
        array $organizationIds,
    ): array {
        $organizationIds = $this->normalizeOrganizationIds(
            $organizationIds,
        );

        if ($organizationIds === []) {
            return [];
        }

        return array_values(
            OrganizationMembership::query()
                ->whereIn('organization_id', $organizationIds)
                ->where(
                    static fn ($query) => $query
                        ->where('user_id', $userId)
                        ->orWhere(
                            'role',
                            OrganizationRole::Owner->value,
                        ),
                )
                ->orderBy('organization_id')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->map(
                    static fn (
                        OrganizationMembership $membership,
                    ): OrganizationMembershipData => new OrganizationMembershipData(
                        id: $membership->id,
                        organizationId: $membership->organization_id,
                        userId: $membership->user_id,
                        role: $membership->role,
                    ),
                )
                ->values()
                ->all(),
        );
    }

    /**
     * Remove all memberships owned by the user.
     */
    public function deleteMembershipsForUser(int $userId): void
    {
        OrganizationMembership::query()
            ->where('user_id', $userId)
            ->delete();
    }

    /**
     * Delete the user through the Eloquent model lifecycle.
     */
    public function deleteUser(int $userId): bool
    {
        $user = User::query()->find($userId);

        return $user instanceof User
            && (bool) $user->delete();
    }

    /**
     * Normalize IDs so every caller obtains locks in the same order.
     *
     * @param  list<int>  $organizationIds
     * @return list<int>
     */
    private function normalizeOrganizationIds(
        array $organizationIds,
    ): array {
        $normalized = array_values(
            array_unique($organizationIds),
        );

        sort($normalized, SORT_NUMERIC);

        return $normalized;
    }
}
