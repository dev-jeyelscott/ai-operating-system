<?php

declare(strict_types=1);

namespace App\Application\Identity\Data;

/**
 * Represents the organization context available to one authenticated user.
 */
final readonly class OrganizationContextData
{
    /**
     * Create an immutable organization context result.
     *
     * @param  list<OrganizationData>  $availableOrganizations
     */
    public function __construct(
        public ?OrganizationData $currentOrganization,
        public array $availableOrganizations,
    ) {}
}
