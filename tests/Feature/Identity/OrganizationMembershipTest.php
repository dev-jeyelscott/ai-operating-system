<?php

declare(strict_types=1);

use App\Application\Identity\AddOrganizationMember;
use App\Application\Identity\CreateOrganization;
use App\Application\Shared\Exceptions\ConflictException;
use App\Domain\Identity\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Database\QueryException;

test('a verified user can create an organization', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->post(route('organizations.store'), [
            'name' => '  Acme Engineering  ',
        ]);

    $organization = Organization::query()->sole();

    $response
        ->assertRedirect(
            route('organizations.dashboard', [
                'organization' => $organization,
            ]),
        )
        ->assertSessionHas('status', 'organization-created')
        ->assertSessionHas(
            'current_organization_id',
            $organization->id,
        );

    expect($organization->name)
        ->toBe('Acme Engineering')
        ->and($organization->slug)
        ->toStartWith('acme-engineering-');

    $membership = OrganizationMembership::query()->sole();

    expect($membership->organization_id)
        ->toBe($organization->id)
        ->and($membership->user_id)
        ->toBe($user->id)
        ->and($membership->role)
        ->toBe(OrganizationRole::Owner);
});

test('a guest cannot create an organization', function () {
    $this
        ->post(route('organizations.store'), [
            'name' => 'Unauthorized Organization',
        ])
        ->assertRedirect(route('login'));

    $this->assertDatabaseCount('organizations', 0);
    $this->assertDatabaseCount('organization_memberships', 0);
});

test('an unverified user cannot create an organization', function () {
    $user = User::factory()->unverified()->create();

    $this
        ->actingAs($user)
        ->post(route('organizations.store'), [
            'name' => 'Unverified Organization',
        ])
        ->assertRedirect(route('verification.notice'));

    $this->assertDatabaseCount('organizations', 0);
    $this->assertDatabaseCount('organization_memberships', 0);
});

test('organization creation requires a valid name', function () {
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->post(route('organizations.store'), [
            'name' => ' ',
        ])
        ->assertSessionHasErrors('name');

    $this->assertDatabaseCount('organizations', 0);
});

test('organization creation and owner membership are atomic', function () {
    expect(
        fn () => app(CreateOrganization::class)->handle(
            ownerUserId: PHP_INT_MAX,
            name: 'Orphaned Organization',
        ),
    )->toThrow(QueryException::class);

    $this->assertDatabaseMissing('organizations', [
        'name' => 'Orphaned Organization',
    ]);
});

test('a user can belong to multiple organizations', function () {
    $user = User::factory()->create();

    $first = app(CreateOrganization::class)->handle(
        ownerUserId: $user->id,
        name: 'First Organization',
    );

    $second = app(CreateOrganization::class)->handle(
        ownerUserId: $user->id,
        name: 'Second Organization',
    );

    expect($first->id)
        ->not->toBe($second->id)
        ->and($user->organizationMemberships()->count())
        ->toBe(2);
});

test('an organization can contain users with explicit roles', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();

    $organization = app(CreateOrganization::class)->handle(
        ownerUserId: $owner->id,
        name: 'Role Test Organization',
    );

    $membership = app(AddOrganizationMember::class)->handle(
        actorUserId: $owner->id,
        organizationId: $organization->id,
        userId: $member->id,
        role: OrganizationRole::Viewer,
    );

    expect($membership->organizationId)
        ->toBe($organization->id)
        ->and($membership->userId)
        ->toBe($member->id)
        ->and($membership->role)
        ->toBe(OrganizationRole::Viewer);

    $this->assertDatabaseHas('organization_memberships', [
        'organization_id' => $organization->id,
        'user_id' => $member->id,
        'role' => OrganizationRole::Viewer->value,
    ]);
});

test('duplicate organization memberships are rejected', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();

    $organization = app(CreateOrganization::class)->handle(
        ownerUserId: $owner->id,
        name: 'Duplicate Membership Organization',
    );

    app(AddOrganizationMember::class)->handle(
        actorUserId: $owner->id,
        organizationId: $organization->id,
        userId: $member->id,
        role: OrganizationRole::Viewer,
    );

    expect(
        fn () => app(AddOrganizationMember::class)->handle(
            actorUserId: $owner->id,
            organizationId: $organization->id,
            userId: $member->id,
            role: OrganizationRole::Viewer,
        ),
    )->toThrow(
        ConflictException::class,
        'The user already belongs to this organization.',
    );

    expect(
        OrganizationMembership::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $member->id)
            ->count(),
    )->toBe(1);
});

test('deleting an organization removes its memberships', function () {
    $owner = User::factory()->create();

    $organizationData = app(CreateOrganization::class)->handle(
        ownerUserId: $owner->id,
        name: 'Disposable Organization',
    );

    $organization = Organization::query()->findOrFail(
        $organizationData->id,
    );

    $organization->delete();

    $this->assertDatabaseMissing('organization_memberships', [
        'organization_id' => $organizationData->id,
    ]);
});
