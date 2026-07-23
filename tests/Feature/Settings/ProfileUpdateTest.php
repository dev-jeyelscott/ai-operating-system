<?php

declare(strict_types=1);

use App\Application\Identity\Contracts\AccountDeletionRepository;
use App\Application\Identity\Data\OrganizationMembershipData;
use App\Domain\Audit\AuditActorType;
use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\AuditSubjectType;
use App\Domain\Identity\OrganizationRole;
use App\Infrastructure\Persistence\Repositories\Identity\EloquentAccountDeletionRepository;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('profile.edit'));

    $response->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();

    expect($user->name)->toBe('Test User');
    expect($user->email)->toBe('test@example.com');
    expect($user->email_verified_at)->toBeNull();
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => $user->email,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('user can delete their account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->delete(route('profile.destroy'), [
            'password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home'));

    $this->assertGuest();
    expect($user->fresh())->toBeNull();
});

test('user without memberships can delete their account', function (): void {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->withSession([
            '_token' => 'old-csrf-token',
            'account-deletion-marker' => 'present',
        ])
        ->delete(route('profile.destroy'), [
            'password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home'));

    $this->assertGuest();

    $this->assertDatabaseMissing('users', [
        'id' => $user->id,
    ]);

    expect(session('account-deletion-marker'))
        ->toBeNull()
        ->and(session()->token())
        ->not->toBe('old-csrf-token');
});

test('final organization owner cannot delete their account', function (): void {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    $membership = OrganizationMembership::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'role' => OrganizationRole::Owner,
    ]);

    $response = $this
        ->actingAs($user)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), [
            'password' => 'password',
        ]);

    $response
        ->assertSessionHasErrors([
            'account' => 'Add another owner to every organization you own before deleting your account.',
        ])
        ->assertRedirect(route('profile.edit'));

    $this->assertAuthenticatedAs($user);

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
    ]);

    $this->assertDatabaseHas('organization_memberships', [
        'id' => $membership->id,
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'role' => OrganizationRole::Owner->value,
    ]);

    $this->assertDatabaseCount('audit_events', 0);
});

test('non-final owner memberships and account are deleted atomically', function (): void {
    $user = User::factory()->create();
    $otherOwner = User::factory()->create();
    $secondOrganizationOwner = User::factory()->create();

    $ownedOrganization = Organization::factory()->create();
    $memberOrganization = Organization::factory()->create();

    $ownedMembership = OrganizationMembership::factory()->create([
        'organization_id' => $ownedOrganization->id,
        'user_id' => $user->id,
        'role' => OrganizationRole::Owner,
    ]);

    $remainingOwnerMembership =
        OrganizationMembership::factory()->create([
            'organization_id' => $ownedOrganization->id,
            'user_id' => $otherOwner->id,
            'role' => OrganizationRole::Owner,
        ]);

    $memberMembership = OrganizationMembership::factory()->create([
        'organization_id' => $memberOrganization->id,
        'user_id' => $user->id,
        'role' => OrganizationRole::Member,
    ]);

    $secondOrganizationOwnerMembership =
        OrganizationMembership::factory()->create([
            'organization_id' => $memberOrganization->id,
            'user_id' => $secondOrganizationOwner->id,
            'role' => OrganizationRole::Owner,
        ]);

    $response = $this
        ->withHeader(
            'X-Request-ID',
            'account-deletion-success-001',
        )
        ->actingAs($user)
        ->delete(route('profile.destroy'), [
            'password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home'));

    $this->assertGuest();

    $this->assertDatabaseMissing('users', [
        'id' => $user->id,
    ]);

    $this->assertDatabaseMissing('organization_memberships', [
        'id' => $ownedMembership->id,
    ]);

    $this->assertDatabaseMissing('organization_memberships', [
        'id' => $memberMembership->id,
    ]);

    $this->assertDatabaseHas('organization_memberships', [
        'id' => $remainingOwnerMembership->id,
    ]);

    $this->assertDatabaseHas('organization_memberships', [
        'id' => $secondOrganizationOwnerMembership->id,
    ]);

    $this->assertDatabaseCount('audit_events', 2);

    $ownedEvent = AuditEvent::query()
        ->where(
            'event_type',
            AuditEventType::OrganizationMemberRemoved->value,
        )
        ->where('organization_id', $ownedOrganization->id)
        ->sole();

    expect($ownedEvent->actor_type)
        ->toBe(AuditActorType::User)
        ->and($ownedEvent->actor_id)
        ->toBe((string) $user->id)
        ->and($ownedEvent->subject_type)
        ->toBe(AuditSubjectType::OrganizationMembership)
        ->and($ownedEvent->subject_id)
        ->toBe((string) $ownedMembership->id)
        ->and($ownedEvent->correlation_id)
        ->toBe('account-deletion-success-001')
        ->and($ownedEvent->metadata)
        ->toMatchArray([
            'member_user_id' => $user->id,
            'role' => OrganizationRole::Owner->value,
            'reason' => 'account_deleted',
        ]);

    $memberEvent = AuditEvent::query()
        ->where(
            'event_type',
            AuditEventType::OrganizationMemberRemoved->value,
        )
        ->where('organization_id', $memberOrganization->id)
        ->sole();

    expect($memberEvent->subject_id)
        ->toBe((string) $memberMembership->id)
        ->and($memberEvent->metadata)
        ->toMatchArray([
            'member_user_id' => $user->id,
            'role' => OrganizationRole::Member->value,
            'reason' => 'account_deleted',
        ]);
});

test('failed account deletion rolls back and preserves authentication', function (): void {
    $user = User::factory()->create();
    $organizationOwner = User::factory()->create();
    $organization = Organization::factory()->create();

    $membership = OrganizationMembership::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'role' => OrganizationRole::Member,
    ]);

    OrganizationMembership::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => $organizationOwner->id,
        'role' => OrganizationRole::Owner,
    ]);

    $repository = app(
        EloquentAccountDeletionRepository::class,
    );

    $this->app->instance(
        AccountDeletionRepository::class,
        new class($repository) implements AccountDeletionRepository
        {
            /**
             * Wrap the real repository and fail immediately before user deletion.
             */
            public function __construct(
                private readonly AccountDeletionRepository $repository,
            ) {}

            /**
             * Delegate user locking to the real repository.
             */
            public function lockUser(int $userId): bool
            {
                return $this->repository->lockUser($userId);
            }

            /**
             * Delegate organization discovery to the real repository.
             *
             * @return list<int>
             */
            public function organizationIdsForUser(
                int $userId,
            ): array {
                return $this->repository
                    ->organizationIdsForUser($userId);
            }

            /**
             * Delegate deterministic organization locking.
             *
             * @param  list<int>  $organizationIds
             */
            public function lockOrganizations(
                array $organizationIds,
            ): void {
                $this->repository->lockOrganizations(
                    $organizationIds,
                );
            }

            /**
             * Delegate membership locking.
             *
             * @param  list<int>  $organizationIds
             * @return list<OrganizationMembershipData>
             */
            public function lockDeletionMemberships(
                int $userId,
                array $organizationIds,
            ): array {
                return $this->repository
                    ->lockDeletionMemberships(
                        userId: $userId,
                        organizationIds: $organizationIds,
                    );
            }

            /**
             * Delete memberships so transaction rollback is exercised.
             */
            public function deleteMembershipsForUser(
                int $userId,
            ): void {
                $this->repository
                    ->deleteMembershipsForUser($userId);
            }

            /**
             * Simulate an infrastructure failure after membership deletion.
             */
            public function deleteUser(int $userId): bool
            {
                throw new RuntimeException(
                    'Simulated user deletion failure.',
                );
            }
        },
    );

    $this->withoutExceptionHandling();

    expect(
        fn () => $this
            ->actingAs($user)
            ->delete(route('profile.destroy'), [
                'password' => 'password',
            ]),
    )->toThrow(
        RuntimeException::class,
        'Simulated user deletion failure.',
    );

    $this->assertAuthenticatedAs($user);

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
    ]);

    $this->assertDatabaseHas('organization_memberships', [
        'id' => $membership->id,
        'user_id' => $user->id,
    ]);

    $this->assertDatabaseCount('audit_events', 0);
});

test('correct password must be provided to delete account', function (): void {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), [
            'password' => 'wrong-password',
        ]);

    $response
        ->assertSessionHasErrors('password')
        ->assertRedirect(route('profile.edit'));

    $this->assertAuthenticatedAs($user);

    expect($user->fresh())->not->toBeNull();
});
