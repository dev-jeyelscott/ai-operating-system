<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Repositories\Identity;

use App\Application\Identity\Contracts\OrganizationRepository;
use App\Application\Identity\Data\OrganizationData;
use App\Application\Identity\Data\OrganizationMembershipData;
use App\Application\Shared\Exceptions\ConflictException;
use App\Domain\Identity\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Persists Identity organization data through Eloquent and PostgreSQL.
 */
final class EloquentOrganizationRepository implements OrganizationRepository
{
    /**
     * Create an organization and initial owner in one transaction.
     */
    public function createWithOwner(
        int $ownerUserId,
        string $name,
    ): OrganizationData {
        return DB::transaction(
            function () use ($ownerUserId, $name): OrganizationData {
                $organization = Organization::query()->create([
                    'name' => $name,
                    'slug' => $this->generateUniqueSlug($name),
                ]);

                OrganizationMembership::query()->create([
                    'organization_id' => $organization->id,
                    'user_id' => $ownerUserId,
                    'role' => OrganizationRole::Owner,
                ]);

                return new OrganizationData(
                    id: $organization->id,
                    name: $organization->name,
                    slug: $organization->slug,
                );
            },
            attempts: 3,
        );
    }

    /**
     * Create a membership unless the user already belongs to the organization.
     */
    public function addMember(
        int $organizationId,
        int $userId,
        OrganizationRole $role,
    ): OrganizationMembershipData {
        return DB::transaction(
            function () use (
                $organizationId,
                $userId,
                $role,
            ): OrganizationMembershipData {
                $membership = OrganizationMembership::query()->firstOrCreate(
                    [
                        'organization_id' => $organizationId,
                        'user_id' => $userId,
                    ],
                    [
                        'role' => $role,
                    ],
                );

                if (! $membership->wasRecentlyCreated) {
                    throw new ConflictException(
                        'The user already belongs to this organization.',
                    );
                }

                return new OrganizationMembershipData(
                    id: $membership->id,
                    organizationId: $membership->organization_id,
                    userId: $membership->user_id,
                    role: $membership->role,
                );
            },
            attempts: 3,
        );
    }

    /**
     * Generate a globally unique and route-safe organization slug.
     */
    private function generateUniqueSlug(string $name): string
    {
        $prefix = Str::slug($name);

        if ($prefix === '') {
            $prefix = 'organization';
        }

        return sprintf(
            '%s-%s',
            Str::limit($prefix, 120, ''),
            strtolower((string) Str::ulid()),
        );
    }

    /**
     * Return every organization accessible to the given user.
     *
     * @return list<OrganizationData>
     */
    public function listForUser(int $userId): array
    {
        $organizations = Organization::query()
            ->whereHas(
                'memberships',
                static fn ($query) => $query->where('user_id', $userId),
            )
            ->orderBy('name')
            ->get()
            ->map(
                static fn (Organization $organization): OrganizationData => new OrganizationData(
                    id: $organization->id,
                    name: $organization->name,
                    slug: $organization->slug,
                ),
            )
            ->all();

        return array_values($organizations);
    }
}
