<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Identity\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationMembership>
 */
final class OrganizationMembershipFactory extends Factory
{
    /**
     * Define a valid organization membership.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'user_id' => User::factory(),
            'role' => OrganizationRole::Member,
        ];
    }

    /**
     * Mark the generated membership as an organization owner.
     */
    public function owner(): static
    {
        return $this->state(
            fn (): array => [
                'role' => OrganizationRole::Owner,
            ],
        );
    }

    /**
     * Mark the generated membership as an organization administrator.
     */
    public function administrator(): static
    {
        return $this->state(
            fn (): array => [
                'role' => OrganizationRole::Administrator,
            ],
        );
    }

    /**
     * Mark the generated membership as read-only.
     */
    public function viewer(): static
    {
        return $this->state(
            fn (): array => [
                'role' => OrganizationRole::Viewer,
            ],
        );
    }
}
