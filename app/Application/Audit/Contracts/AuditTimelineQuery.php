<?php

declare(strict_types=1);

namespace App\Application\Audit\Contracts;

use App\Application\Audit\Data\AuditTimelineCriteria;
use App\Models\AuditEvent;
use Illuminate\Contracts\Pagination\CursorPaginator;

/**
 * Read-only query boundary for tenant-scoped audit timelines.
 */
interface AuditTimelineQuery
{
    /**
     * Return one deterministic cursor-paginated slice of audit history.
     *
     * @return CursorPaginator<int, AuditEvent>
     */
    public function paginate(
        AuditTimelineCriteria $criteria,
    ): CursorPaginator;
}
