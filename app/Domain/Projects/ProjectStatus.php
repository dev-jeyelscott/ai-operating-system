<?php

declare(strict_types=1);

namespace App\Domain\Projects;

/**
 * Defines the canonical lifecycle states for a project.
 */
enum ProjectStatus: string
{
    case Draft = 'draft';
    case Configuring = 'configuring';
    case DocumentsPending = 'documents_pending';
    case ReadyForPlanning = 'ready_for_planning';
    case Planning = 'planning';
    case AwaitingRoadmapApproval = 'awaiting_roadmap_approval';
    case ReadyForDevelopment = 'ready_for_development';
    case Active = 'active';
    case Paused = 'paused';
    case Blocked = 'blocked';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /**
     * Return every persisted project status value.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $status): string => $status->value,
            self::cases(),
        );
    }

    /**
     * Determine whether no further lifecycle transition is allowed.
     */
    public function isTerminal(): bool
    {
        return in_array(
            $this,
            [self::Completed, self::Cancelled],
            true,
        );
    }
}
