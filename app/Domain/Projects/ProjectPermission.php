<?php

declare(strict_types=1);

namespace App\Domain\Projects;

/**
 * Defines organization-scoped operations that may be performed on projects.
 */
enum ProjectPermission: string
{
    case View = 'view';
    case Create = 'create';
    case Update = 'update';
    case Approve = 'approve';
    case ManageIntegrations = 'manage_integrations';
    case Archive = 'archive';
    case Restore = 'restore';
}
