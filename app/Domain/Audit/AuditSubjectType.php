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
}
