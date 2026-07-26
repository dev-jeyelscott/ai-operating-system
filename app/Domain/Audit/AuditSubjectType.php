<?php

declare(strict_types=1);

namespace App\Domain\Audit;

/**
 * Identifies the aggregate or resource affected by an audited operation.
 */
enum AuditSubjectType: string
{
    case Organization = 'organization';
    case OrganizationMembership = 'organization_membership';

    case Project = 'project';
    case ProjectConfigurationVersion = 'project_configuration_version';
    case ProjectContextSnapshot = 'project_context_snapshot';

    case Document = 'document';
    case DocumentVersion = 'document_version';

    case ProviderCredential = 'provider_credential';
    case ProjectIntegration = 'project_integration';

    case Approval = 'approval';
    case Execution = 'execution';

    case OutboxMessage = 'outbox_message';
    case FailedQueueJob = 'failed_queue_job';
}
