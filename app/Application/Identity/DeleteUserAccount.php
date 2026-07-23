<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Application\Audit\RecordAuditEvent;
use App\Application\Identity\Contracts\AccountDeletionRepository;
use App\Application\Identity\Data\OrganizationMembershipData;
use App\Application\Identity\Exceptions\AccountDeletionBlocked;
use App\Application\Shared\Contracts\TransactionManager;
use App\Domain\Audit\AuditActorType;
use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\AuditSubjectType;
use App\Domain\Identity\OrganizationRole;
use InvalidArgumentException;

/**
 * Deletes a user account without violating organization ownership invariants.
 */
final readonly class DeleteUserAccount
{
    /**
     * Inject account persistence, audit recording, and transaction handling.
     */
    public function __construct(
        private AccountDeletionRepository $accounts,
        private RecordAuditEvent $audit,
        private TransactionManager $transactions,
    ) {}

    /**
     * Remove the user's memberships and account atomically.
     *
     * The action rejects deletion when the user is the final owner of any
     * organization. Authentication session handling remains an HTTP concern
     * and must happen only after this method successfully returns.
     */
    public function handle(
        int $actorUserId,
        ?string $correlationId = null,
    ): void {
        if ($actorUserId < 1) {
            throw new InvalidArgumentException(
                'The actor user identifier must be positive.',
            );
        }

        $this->transactions->run(
            function () use (
                $actorUserId,
                $correlationId,
            ): void {
                if (! $this->accounts->lockUser($actorUserId)) {
                    throw AccountDeletionBlocked::accountChanged();
                }

                $organizationIds = $this->accounts
                    ->organizationIdsForUser($actorUserId);

                /*
                 * Organization rows are locked before membership rows so
                 * overlapping ownership mutations use a common lock order.
                 */
                $this->accounts->lockOrganizations(
                    $organizationIds,
                );

                $lockedMemberships = $this->accounts
                    ->lockDeletionMemberships(
                        userId: $actorUserId,
                        organizationIds: $organizationIds,
                    );

                $userMemberships = [];
                $ownerCountByOrganization = [];

                foreach ($lockedMemberships as $membership) {
                    if (
                        $membership->role
                        === OrganizationRole::Owner
                    ) {
                        $organizationId =
                            $membership->organizationId;

                        $ownerCountByOrganization[$organizationId] =
                            (
                                $ownerCountByOrganization[
                                    $organizationId
                                ] ?? 0
                            ) + 1;
                    }

                    if ($membership->userId === $actorUserId) {
                        $userMemberships[] = $membership;
                    }
                }

                $this->assertOwnershipRemains(
                    memberships: $userMemberships,
                    ownerCountByOrganization: $ownerCountByOrganization,
                );

                $this->recordMembershipRemovalEvents(
                    actorUserId: $actorUserId,
                    memberships: $userMemberships,
                    correlationId: $correlationId,
                );

                $this->accounts->deleteMembershipsForUser(
                    $actorUserId,
                );

                if (
                    ! $this->accounts->deleteUser(
                        $actorUserId,
                    )
                ) {
                    throw AccountDeletionBlocked::accountChanged();
                }
            },
        );
    }

    /**
     * Ensure each organization retains an owner after account deletion.
     *
     * @param  list<OrganizationMembershipData>  $memberships
     * @param  array<int, int>  $ownerCountByOrganization
     */
    private function assertOwnershipRemains(
        array $memberships,
        array $ownerCountByOrganization,
    ): void {
        foreach ($memberships as $membership) {
            if ($membership->role !== OrganizationRole::Owner) {
                continue;
            }

            $ownerCount = $ownerCountByOrganization[
                $membership->organizationId
            ] ?? 0;

            if ($ownerCount <= 1) {
                throw AccountDeletionBlocked::finalOrganizationOwner();
            }
        }
    }

    /**
     * Append one factual audit event for every removed membership.
     *
     * @param  list<OrganizationMembershipData>  $memberships
     */
    private function recordMembershipRemovalEvents(
        int $actorUserId,
        array $memberships,
        ?string $correlationId,
    ): void {
        foreach ($memberships as $membership) {
            $this->audit->record(
                organizationId: $membership->organizationId,
                projectId: null,
                actorType: AuditActorType::User,
                actorId: (string) $actorUserId,
                eventType: AuditEventType::OrganizationMemberRemoved,
                subjectType: AuditSubjectType::OrganizationMembership,
                subjectId: (string) $membership->id,
                correlationId: $correlationId,
                metadata: [
                    'member_user_id' => $membership->userId,
                    'role' => $membership->role->value,
                    'reason' => 'account_deleted',
                ],
            );
        }
    }
}
