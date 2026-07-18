<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Application\Audit\RecordAuditEvent;
use App\Application\Identity\Contracts\OrganizationRepository;
use App\Application\Identity\Data\OrganizationMembershipData;
use App\Application\Shared\Contracts\TransactionManager;
use App\Domain\Audit\AuditActorType;
use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\AuditSubjectType;
use App\Domain\Identity\OrganizationRole;
use InvalidArgumentException;

/**
 * Adds one user to an organization with an explicit organization role.
 */
final readonly class AddOrganizationMember
{
    /**
     * Inject membership persistence, audit recording, and transactions.
     */
    public function __construct(
        private OrganizationRepository $organizations,
        private RecordAuditEvent $audit,
        private TransactionManager $transactions,
    ) {}

    /**
     * Add the membership and its audit event atomically.
     */
    public function handle(
        int $actorUserId,
        int $organizationId,
        int $userId,
        OrganizationRole $role,
        ?string $correlationId = null,
    ): OrganizationMembershipData {
        if ($actorUserId < 1) {
            throw new InvalidArgumentException(
                'The actor user identifier must be positive.',
            );
        }

        if ($organizationId < 1) {
            throw new InvalidArgumentException(
                'The organization identifier must be positive.',
            );
        }

        if ($userId < 1) {
            throw new InvalidArgumentException(
                'The user identifier must be positive.',
            );
        }

        return $this->transactions->run(
            function () use (
                $actorUserId,
                $organizationId,
                $userId,
                $role,
                $correlationId,
            ): OrganizationMembershipData {
                $membership = $this->organizations->addMember(
                    organizationId: $organizationId,
                    userId: $userId,
                    role: $role,
                );

                $this->audit->record(
                    organizationId: $organizationId,
                    projectId: null,
                    actorType: AuditActorType::User,
                    actorId: (string) $actorUserId,
                    eventType: AuditEventType::OrganizationMemberAdded,
                    subjectType: AuditSubjectType::OrganizationMembership,
                    subjectId: (string) $membership->id,
                    correlationId: $correlationId,
                    metadata: [
                        'member_user_id' => $membership->userId,
                        'role' => $membership->role->value,
                    ],
                );

                return $membership;
            },
        );
    }
}
