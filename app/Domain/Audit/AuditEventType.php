<?php

declare(strict_types=1);

namespace App\Domain\Audit;

/**
 * Defines stable event names produced by currently implemented privileged
 * organization, membership, and project commands.
 */
enum AuditEventType: string
{
    case OrganizationCreated = 'organization.created';
    case OrganizationMemberAdded = 'organization.member.added';
    case OrganizationMemberRemoved = 'organization.member.removed';

    case ProjectCreated = 'project.created';
    case ProjectUpdated = 'project.updated';
    case ProjectArchived = 'project.archived';
    case ProjectRestored = 'project.restored';
    case ProjectSetupUpdated = 'project.setup.updated';

    case IntegrationCredentialStored = 'integration.credential.stored';
    case IntegrationCredentialRotated = 'integration.credential.rotated';
}
