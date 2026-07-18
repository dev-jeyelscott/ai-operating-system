<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Application\Identity\Contracts\OrganizationRepository;
use App\Application\Identity\Data\OrganizationContextData;
use InvalidArgumentException;

/**
 * Resolves the current organization available to an authenticated user.
 */
final readonly class ResolveOrganizationContext
{
    /**
     * Inject organization persistence operations.
     */
    public function __construct(
        private OrganizationRepository $organizations,
    ) {}

    /**
     * Resolve a valid preferred organization or fall back deterministically.
     */
    public function handle(
        int $userId,
        ?int $preferredOrganizationId,
    ): OrganizationContextData {
        if ($userId < 1) {
            throw new InvalidArgumentException(
                'The user identifier must be positive.',
            );
        }

        $availableOrganizations = $this->organizations->listForUser(
            $userId,
        );

        $currentOrganization = null;

        if ($preferredOrganizationId !== null) {
            foreach ($availableOrganizations as $organization) {
                if ($organization->id === $preferredOrganizationId) {
                    $currentOrganization = $organization;

                    break;
                }
            }
        }

        $currentOrganization ??= $availableOrganizations[0] ?? null;

        return new OrganizationContextData(
            currentOrganization: $currentOrganization,
            availableOrganizations: $availableOrganizations,
        );
    }
}
