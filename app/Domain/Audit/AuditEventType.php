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

    case ProjectCreated = 'project.created';
    case ProjectUpdated = 'project.updated';
    case ProjectArchived = 'project.archived';
    case ProjectRestored = 'project.restored';
}
