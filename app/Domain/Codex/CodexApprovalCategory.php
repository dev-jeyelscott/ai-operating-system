<?php

declare(strict_types=1);

namespace App\Domain\Codex;

/**
 * Describes the consequential resource class requested by Codex.
 */
enum CodexApprovalCategory: string
{
    case Command = 'command';
    case FileWrite = 'file_write';
    case Network = 'network';
    case PermissionEscalation = 'permission_escalation';
}
