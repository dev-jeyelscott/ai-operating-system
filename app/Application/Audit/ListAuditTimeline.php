<?php

declare(strict_types=1);

namespace App\Application\Audit;

use App\Application\Audit\Contracts\AuditTimelineQuery;
use App\Application\Audit\Data\AuditTimelineCriteria;
use App\Models\AuditEvent;
use Illuminate\Contracts\Pagination\CursorPaginator;

/**
 * Lists authoritative audit history through the Audit module query boundary.
 */
final readonly class ListAuditTimeline
{
    /**
     * Inject the audit timeline query implementation.
     */
    public function __construct(
        private AuditTimelineQuery $timeline,
    ) {}

    /**
     * Return one tenant-scoped, deterministic timeline page.
     *
     * Authorization must be completed by the calling application boundary
     * before constructing the organization-scoped criteria.
     *
     * @return CursorPaginator<int, AuditEvent>
     */
    public function handle(
        AuditTimelineCriteria $criteria,
    ): CursorPaginator {
        return $this->timeline->paginate($criteria);
    }
}
