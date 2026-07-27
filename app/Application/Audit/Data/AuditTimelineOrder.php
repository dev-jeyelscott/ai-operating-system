<?php

declare(strict_types=1);

namespace App\Application\Audit\Data;

/**
 * Defines the supported deterministic audit timeline directions.
 */
enum AuditTimelineOrder: string
{
    case OldestFirst = 'oldest_first';
    case NewestFirst = 'newest_first';

    /**
     * Return the SQL direction used for both stable ordering columns.
     *
     * @return 'asc'|'desc'
     */
    public function direction(): string
    {
        return match ($this) {
            self::OldestFirst => 'asc',
            self::NewestFirst => 'desc',
        };
    }
}
