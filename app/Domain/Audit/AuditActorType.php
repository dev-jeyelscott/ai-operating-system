<?php

declare(strict_types=1);

namespace App\Domain\Audit;

/**
 * Identifies the kind of principal responsible for an audited operation.
 */
enum AuditActorType: string
{
    case User = 'user';
    case System = 'system';
    case Agent = 'agent';
    case Provider = 'provider';
}
