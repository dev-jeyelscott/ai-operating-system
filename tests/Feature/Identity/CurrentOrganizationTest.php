<?php

declare(strict_types=1);

use App\Application\Identity\CreateOrganization;
use App\Models\Organization;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Create and reload an organization owned by the supplied user.
 */
function createOrganizationForCurrentContextTest(
    User $user,
    string $name,
): Organization {
    $organization = app(CreateOrganization::class)->handle(
        ownerUserId: $user->id,
        name: $name,
    );

    return Organization::query()->findOrFail($organization->id);
}

test(
    'a user without organizations can open the onboarding dashboard',
    function () {
        $user = User::factory()->create();

        $this
            ->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(
                fn (Assert $page): Assert => $page
                    ->component('dashboard')
                    ->where('organizationContext.current', null)
                    ->has('organizationContext.available', 0),
            );
    },
);

test(
    'the dashboard selects a deterministic organization',
    function () {
        $user = User::factory()->create();

        createOrganizationForCurrentContextTest($user, 'Beta');

        $alpha = createOrganizationForCurrentContextTest(
            $user,
            'Alpha',
        );

        $this
            ->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(
                route('organizations.dashboard', [
                    'organization' => $alpha,
                ]),
            )
            ->assertSessionHas(
                'current_organization_id',
                $alpha->id,
            );
    },
);

test('a member can switch the current organization', function () {
    $user = User::factory()->create();

    $first = createOrganizationForCurrentContextTest(
        $user,
        'First Organization',
    );

    $second = createOrganizationForCurrentContextTest(
        $user,
        'Second Organization',
    );

    $this
        ->actingAs($user)
        ->withSession([
            'current_organization_id' => $first->id,
        ])
        ->put(
            route('organizations.current.update', [
                'organization' => $second,
            ]),
        )
        ->assertRedirect(
            route('organizations.dashboard', [
                'organization' => $second,
            ]),
        )
        ->assertSessionHas(
            'current_organization_id',
            $second->id,
        );
});

test(
    'a non-member cannot switch to another organization',
    function () {
        $user = User::factory()->create();

        $current = createOrganizationForCurrentContextTest(
            $user,
            'Current Organization',
        );

        $otherUser = User::factory()->create();

        $forbidden = createOrganizationForCurrentContextTest(
            $otherUser,
            'Forbidden Organization',
        );

        $this
            ->actingAs($user)
            ->withSession([
                'current_organization_id' => $current->id,
            ])
            ->put(
                route('organizations.current.update', [
                    'organization' => $forbidden,
                ]),
            )
            ->assertNotFound()
            ->assertSessionHas(
                'current_organization_id',
                $current->id,
            );
    },
);

test(
    'the scoped dashboard shares only the users organizations',
    function () {
        $user = User::factory()->create();

        $alpha = createOrganizationForCurrentContextTest(
            $user,
            'Alpha',
        );

        $beta = createOrganizationForCurrentContextTest(
            $user,
            'Beta',
        );

        $otherUser = User::factory()->create();

        createOrganizationForCurrentContextTest(
            $otherUser,
            'Hidden Organization',
        );

        $this
            ->actingAs($user)
            ->get(
                route('organizations.dashboard', [
                    'organization' => $beta,
                ]),
            )
            ->assertOk()
            ->assertSessionHas(
                'current_organization_id',
                $beta->id,
            )
            ->assertInertia(
                fn (Assert $page): Assert => $page
                    ->component('dashboard')
                    ->where(
                        'organizationContext.current.id',
                        $beta->id,
                    )
                    ->where(
                        'organizationContext.current.slug',
                        $beta->slug,
                    )
                    ->has('organizationContext.available', 2)
                    ->where(
                        'organizationContext.available.0.id',
                        $alpha->id,
                    )
                    ->where(
                        'organizationContext.available.1.id',
                        $beta->id,
                    ),
            );
    },
);
