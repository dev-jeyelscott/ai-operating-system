<?php

declare(strict_types=1);

namespace App\Domain\Events;

/**
 * Identifies the kind of principal responsible for a domain event.
 */
enum DomainEventActorType: string
{
    case User = 'user';
    case System = 'system';
    case Agent = 'agent';
    case Provider = 'provider';
}
