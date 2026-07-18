<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Application\Identity\Contracts\OrganizationRepository;
use App\Application\Identity\Data\OrganizationData;
use InvalidArgumentException;

/**
 * Creates an organization with the requesting user as its first owner.
 */
final readonly class CreateOrganization
{
    /**
     * Inject the persistence contract required by the use case.
     */
    public function __construct(
        private OrganizationRepository $organizations,
    ) {}

    /**
     * Validate the organization name and create the organization atomically.
     */
    public function handle(
        int $ownerUserId,
        string $name,
    ): OrganizationData {
        $normalizedName = trim($name);

        if ($ownerUserId < 1) {
            throw new InvalidArgumentException(
                'The owner user identifier must be positive.',
            );
        }

        if ($normalizedName === '') {
            throw new InvalidArgumentException(
                'The organization name is required.',
            );
        }

        if (mb_strlen($normalizedName) > 120) {
            throw new InvalidArgumentException(
                'The organization name may not exceed 120 characters.',
            );
        }

        return $this->organizations->createWithOwner(
            ownerUserId: $ownerUserId,
            name: $normalizedName,
        );
    }
}
