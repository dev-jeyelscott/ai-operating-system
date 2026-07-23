<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Application\Audit\RecordAuditEvent;
use App\Application\Identity\Contracts\OrganizationRepository;
use App\Application\Identity\Data\OrganizationData;
use App\Application\Shared\Contracts\TransactionManager;
use App\Domain\Audit\AuditActorType;
use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\AuditSubjectType;
use App\Domain\Identity\OrganizationRole;
use InvalidArgumentException;
use LogicException;

/**
 * Creates an organization with the requesting user as its first owner.
 */
final readonly class CreateOrganization
{
    /**
     * Inject organization persistence, audit recording, and transactions.
     */
    public function __construct(
        private OrganizationRepository $organizations,
        private RecordAuditEvent $audit,
        private TransactionManager $transactions,
    ) {}

    /**
     * Validate and create the organization and owner audit history atomically.
     */
    public function handle(
        int $ownerUserId,
        string $name,
        ?string $correlationId = null,
    ): OrganizationData {
        $normalizedName = trim($name);

        if ($ownerUserId < 1) {
            throw new InvalidArgumentException(
                'The owner user identifier must be positive.',
            );
        }

        if ($normalizedName === '') {
            throw new InvalidArgumentException(
                'The organization name is required.',
            );
        }

        if (mb_strlen($normalizedName) > 120) {
            throw new InvalidArgumentException(
                'The organization name may not exceed 120 characters.',
            );
        }

        return $this->transactions->run(
            function () use (
                $ownerUserId,
                $normalizedName,
                $correlationId,
            ): OrganizationData {
                $organization = $this->organizations->createWithOwner(
                    ownerUserId: $ownerUserId,
                    name: $normalizedName,
                );

                $ownerMembershipId = $organization->ownerMembershipId;

                if ($ownerMembershipId === null) {
                    throw new LogicException(
                        'Organization creation did not return its owner membership.',
                    );
                }

                $this->audit->record(
                    organizationId: $organization->id,
                    projectId: null,
                    actorType: AuditActorType::User,
                    actorId: (string) $ownerUserId,
                    eventType: AuditEventType::OrganizationCreated,
                    subjectType: AuditSubjectType::Organization,
                    subjectId: (string) $organization->id,
                    correlationId: $correlationId,
                    metadata: [
                        'name' => $organization->name,
                    ],
                );

                $this->audit->record(
                    organizationId: $organization->id,
                    projectId: null,
                    actorType: AuditActorType::User,
                    actorId: (string) $ownerUserId,
                    eventType: AuditEventType::OrganizationMemberAdded,
                    subjectType: AuditSubjectType::OrganizationMembership,
                    subjectId: (string) $ownerMembershipId,
                    correlationId: $correlationId,
                    metadata: [
                        'member_user_id' => $ownerUserId,
                        'role' => OrganizationRole::Owner->value,
                        'source' => 'organization_creation',
                    ],
                );

                return $organization;
            },
        );
    }
}
