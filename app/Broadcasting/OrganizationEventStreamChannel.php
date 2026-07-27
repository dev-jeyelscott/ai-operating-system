<?php

declare(strict_types=1);

namespace App\Broadcasting;

use App\Models\OrganizationMembership;
use App\Models\User;

/**
 * Authorizes organization members to receive organization-level stream events.
 */
final readonly class OrganizationEventStreamChannel
{
    /**
     * Determine whether the authenticated user belongs to the organization.
     */
    public function join(
        User $user,
        int|string $organizationId,
    ): bool {
        $organizationId = (int) $organizationId;

        if ($organizationId < 1) {
            return false;
        }

        return OrganizationMembership::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $user->id)
            ->exists();
    }
}
