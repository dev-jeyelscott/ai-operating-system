<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Application\Identity\Contracts\OrganizationRepository;
use App\Application\Identity\Data\OrganizationMembershipData;
use App\Domain\Identity\OrganizationRole;
use InvalidArgumentException;

/**
 * Adds one user to an organization with an explicit organization role.
 *
 * Authorization is deliberately handled by the future AIOS-013 policy layer.
 */
final readonly class AddOrganizationMember
{
    /**
     * Inject the persistence contract required by the use case.
     */
    public function __construct(
        private OrganizationRepository $organizations,
    ) {}

    /**
     * Add the membership after validating identifier boundaries.
     */
    public function handle(
        int $organizationId,
        int $userId,
        OrganizationRole $role,
    ): OrganizationMembershipData {
        if ($organizationId < 1) {
            throw new InvalidArgumentException(
                'The organization identifier must be positive.',
            );
        }

        if ($userId < 1) {
            throw new InvalidArgumentException(
                'The user identifier must be positive.',
            );
        }

        return $this->organizations->addMember(
            organizationId: $organizationId,
            userId: $userId,
            role: $role,
        );
    }
}
