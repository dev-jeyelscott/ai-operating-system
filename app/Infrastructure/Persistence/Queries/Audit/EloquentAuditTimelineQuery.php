<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Queries\Audit;

use App\Application\Audit\Contracts\AuditTimelineQuery;
use App\Application\Audit\Data\AuditTimelineCriteria;
use App\Models\AuditEvent;
use Illuminate\Contracts\Pagination\CursorPaginator;

/**
 * Queries append-only audit history through Eloquent and PostgreSQL.
 */
final readonly class EloquentAuditTimelineQuery implements AuditTimelineQuery
{
    /**
     * Return one filtered timeline page ordered by sequence, then timestamp.
     *
     * Every query starts with the mandatory organization condition. Optional
     * project and trace filters may only narrow that tenant-scoped result.
     *
     * @return CursorPaginator<int, AuditEvent>
     */
    public function paginate(
        AuditTimelineCriteria $criteria,
    ): CursorPaginator {
        $query = AuditEvent::query()
            ->where('organization_id', $criteria->organizationId);

        if ($criteria->projectId !== null) {
            $query->where('project_id', $criteria->projectId);
        }

        if ($criteria->executionId !== null) {
            $query->where('execution_id', $criteria->executionId);
        }

        if ($criteria->correlationId !== null) {
            $query->where('correlation_id', $criteria->correlationId);
        }

        if ($criteria->causationId !== null) {
            $query->where('causation_id', $criteria->causationId);
        }

        if ($criteria->eventType !== null) {
            $query->where('event_type', $criteria->eventType->value);
        }

        if ($criteria->subjectType !== null) {
            $query->where(
                'subject_type',
                $criteria->subjectType->value,
            );
        }

        if ($criteria->subjectId !== null) {
            $query->where('subject_id', $criteria->subjectId);
        }

        $direction = $criteria->order->direction();

        return $query
            ->orderBy('sequence', $direction)
            ->orderBy('occurred_at', $direction)
            ->cursorPaginate(
                perPage: $criteria->perPage,
                columns: ['*'],
                cursorName: 'cursor',
                cursor: $criteria->cursor,
            );
    }
}
