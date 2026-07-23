<?php

declare(strict_types=1);

namespace App\Application\Audit\Contracts;

use App\Application\Audit\Data\AuditEventData;

/**
 * Append-only persistence boundary owned by the Audit module.
 */
interface AuditEventRepository
{
    /**
     * Append one immutable audit event.
     *
     * Implementations must never expose update or delete operations.
     */
    public function append(AuditEventData $event): void;
}
