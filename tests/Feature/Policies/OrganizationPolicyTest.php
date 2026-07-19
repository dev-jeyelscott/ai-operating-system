<?php

declare(strict_types=1);

use App\Domain\Identity\OrganizationRole;
use App\Domain\Projects\ProjectPermissionMatrix;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Policies\OrganizationPolicy;
use Illuminate\Auth\Access\Response;

/**
 * Attach one explicit role to a user for the supplied organization.
 */
function attachOrganizationPolicyMembership(
    User $user,
    Organization $organization,
    OrganizationRole $role,
): void {
    OrganizationMembership::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'role' => $role,
    ]);
}

/**
 * Execute one organization policy ability without using a dynamic method call.
 *
 * The explicit match keeps static analysis aware of every supported ability.
 */
function inspectOrganizationPolicyAbility(
    OrganizationPolicy $policy,
    string $ability,
    User $user,
    Organization $organization,
): Response {
    return match ($ability) {
        'view' => $policy->view($user, $organization),
        'update' => $policy->update($user, $organization),
        'delete' => $policy->delete($user, $organization),
        'manageMembers' => $policy->manageMembers($user, $organization),
        'transferOwnership' => $policy->transferOwnership(
            $user,
            $organization,
        ),
        'createProject' => $policy->createProject($user, $organization),
        default => throw new LogicException(
            "Unsupported organization policy ability: {$ability}",
        ),
    };
}

/**
 * Return every role and organization-policy decision expected by production.
 *
 * @return array<string, array{OrganizationRole, string, bool}>
 */
function organizationPolicyRoleCases(): array
{
    return [
        'owner may view the organization' => [
            OrganizationRole::Owner,
            'view',
            true,
        ],
        'owner may update the organization' => [
            OrganizationRole::Owner,
            'update',
            true,
        ],
        'owner may delete the organization' => [
            OrganizationRole::Owner,
            'delete',
            true,
        ],
        'owner may manage members' => [
            OrganizationRole::Owner,
            'manageMembers',
            true,
        ],
        'owner may transfer ownership' => [
            OrganizationRole::Owner,
            'transferOwnership',
            true,
        ],
        'owner may create projects' => [
            OrganizationRole::Owner,
            'createProject',
            true,
        ],

        'administrator may view the organization' => [
            OrganizationRole::Administrator,
            'view',
            true,
        ],
        'administrator may update the organization' => [
            OrganizationRole::Administrator,
            'update',
            true,
        ],
        'administrator may not delete the organization' => [
            OrganizationRole::Administrator,
            'delete',
            false,
        ],
        'administrator may manage members' => [
            OrganizationRole::Administrator,
            'manageMembers',
            true,
        ],
        'administrator may not transfer ownership' => [
            OrganizationRole::Administrator,
            'transferOwnership',
            false,
        ],
        'administrator may create projects' => [
            OrganizationRole::Administrator,
            'createProject',
            true,
        ],

        'member may view the organization' => [
            OrganizationRole::Member,
            'view',
            true,
        ],
        'member may not update the organization' => [
            OrganizationRole::Member,
            'update',
            false,
        ],
        'member may not delete the organization' => [
            OrganizationRole::Member,
            'delete',
            false,
        ],
        'member may not manage members' => [
            OrganizationRole::Member,
            'manageMembers',
            false,
        ],
        'member may not transfer ownership' => [
            OrganizationRole::Member,
            'transferOwnership',
            false,
        ],
        'member may create projects' => [
            OrganizationRole::Member,
            'createProject',
            true,
        ],

        'viewer may view the organization' => [
            OrganizationRole::Viewer,
            'view',
            true,
        ],
        'viewer may not update the organization' => [
            OrganizationRole::Viewer,
            'update',
            false,
        ],
        'viewer may not delete the organization' => [
            OrganizationRole::Viewer,
            'delete',
            false,
        ],
        'viewer may not manage members' => [
            OrganizationRole::Viewer,
            'manageMembers',
            false,
        ],
        'viewer may not transfer ownership' => [
            OrganizationRole::Viewer,
            'transferOwnership',
            false,
        ],
        'viewer may not create projects' => [
            OrganizationRole::Viewer,
            'createProject',
            false,
        ],
    ];
}

/**
 * Return organization abilities that require membership in the target tenant.
 *
 * @return list<string>
 */
function organizationPolicyScopedAbilities(): array
{
    return [
        'view',
        'update',
        'delete',
        'manageMembers',
        'transferOwnership',
        'createProject',
    ];
}

dataset('organization policy role decisions', organizationPolicyRoleCases());
dataset('organization policy scoped abilities', organizationPolicyScopedAbilities());

test(
    'organization policy resolves the configured role decision for :dataset',
    function (
        OrganizationRole $role,
        string $ability,
        bool $expectedAllowed,
    ): void {
        $organization = Organization::factory()->create();
        $user = User::factory()->create();

        attachOrganizationPolicyMembership(
            user: $user,
            organization: $organization,
            role: $role,
        );

        $policy = new OrganizationPolicy(new ProjectPermissionMatrix);
        $response = inspectOrganizationPolicyAbility(
            policy: $policy,
            ability: $ability,
            user: $user,
            organization: $organization,
        );

        expect($response->allowed())
            ->toBe($expectedAllowed)
            ->and($response->status())
            ->toBeNull();

        if (! $expectedAllowed) {
            expect($response->denied())
                ->toBeTrue()
                ->and($response->message())
                ->not->toBeNull();
        }
    },
)->with('organization policy role decisions');

test(
    'a non-member receives a not-found policy response for :dataset',
    function (string $ability): void {
        $organization = Organization::factory()->create();
        $nonMember = User::factory()->create();
        $policy = new OrganizationPolicy(new ProjectPermissionMatrix);

        $response = inspectOrganizationPolicyAbility(
            policy: $policy,
            ability: $ability,
            user: $nonMember,
            organization: $organization,
        );

        expect($response->denied())
            ->toBeTrue()
            ->and($response->status())
            ->toBe(404);
    },
)->with('organization policy scoped abilities');

test('only verified users may create an organization', function (): void {
    $policy = new OrganizationPolicy(new ProjectPermissionMatrix);
    $verifiedUser = User::factory()->create();
    $unverifiedUser = User::factory()->unverified()->create();

    expect($policy->create($verifiedUser))
        ->toBeTrue()
        ->and($policy->create($unverifiedUser))
        ->toBeFalse();
});

test('users may list organizations only when they have a membership', function (): void {
    $organization = Organization::factory()->create();
    $member = User::factory()->create();
    $nonMember = User::factory()->create();

    attachOrganizationPolicyMembership(
        user: $member,
        organization: $organization,
        role: OrganizationRole::Viewer,
    );

    $policy = new OrganizationPolicy(new ProjectPermissionMatrix);

    expect($policy->viewAny($member))
        ->toBeTrue()
        ->and($policy->viewAny($nonMember))
        ->toBeFalse();
});

test(
    'organization role dataset covers every scoped role and ability pair exactly once',
    function (): void {
        $coveredPairs = [];

        foreach (organizationPolicyRoleCases() as [$role, $ability]) {
            $coveredPairs[] = $role->value.':'.$ability;
        }

        $expectedPairCount = count(OrganizationRole::cases())
            * count(organizationPolicyScopedAbilities());

        expect($coveredPairs)
            ->toHaveCount($expectedPairCount)
            ->and(array_values(array_unique($coveredPairs)))
            ->toHaveCount($expectedPairCount);
    },
);
