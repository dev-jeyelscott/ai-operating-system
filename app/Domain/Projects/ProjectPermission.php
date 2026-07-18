<?php

declare(strict_types=1);

namespace App\Domain\Projects;

/**
 * Defines the organization-scoped operations that may be performed on projects.
 */
enum ProjectPermission: string
{
    case View = 'view';
    case Create = 'create';
    case Update = 'update';
    case Archive = 'archive';
    case Restore = 'restore';
}
