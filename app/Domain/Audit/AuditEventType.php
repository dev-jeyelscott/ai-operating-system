<?php

declare(strict_types=1);

namespace App\Domain\Audit;

/**
 * Defines stable event names produced by currently implemented privileged
 * organization, membership, project, and integration commands.
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
    case ProjectConfigurationVersionCreated =
        'project.configuration.version.created';

    case IntegrationCredentialStored = 'integration.credential.stored';
    case IntegrationCredentialRotated = 'integration.credential.rotated';

    case NotionConnectionTestSucceeded =
        'integration.notion.connection_test.succeeded';

    case NotionConnectionTestFailed =
        'integration.notion.connection_test.failed';
}
