<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\Domain\Projects\Exceptions\InvalidProjectStatusTransition;

/**
 * Owns the deterministic project lifecycle transition rules.
 */
final class ProjectLifecycle
{
    /**
     * Determine whether the requested transition is permitted.
     */
    public function canTransition(
        ProjectStatus $from,
        ProjectStatus $to,
    ): bool {
        return in_array(
            $to,
            $this->allowedTransitions($from),
            true,
        );
    }

    /**
     * Reject the transition when it is not part of the lifecycle definition.
     */
    public function assertCanTransition(
        ProjectStatus $from,
        ProjectStatus $to,
    ): void {
        if (! $this->canTransition($from, $to)) {
            throw InvalidProjectStatusTransition::between(
                from: $from,
                to: $to,
            );
        }
    }

    /**
     * Return the exact states reachable from the supplied status.
     *
     * @return list<ProjectStatus>
     */
    public function allowedTransitions(ProjectStatus $from): array
    {
        return match ($from) {
            ProjectStatus::Draft => [
                ProjectStatus::Configuring,
                ProjectStatus::Cancelled,
            ],

            ProjectStatus::Configuring => [
                ProjectStatus::DocumentsPending,
                ProjectStatus::Blocked,
                ProjectStatus::Cancelled,
            ],

            ProjectStatus::DocumentsPending => [
                ProjectStatus::Configuring,
                ProjectStatus::ReadyForPlanning,
                ProjectStatus::Blocked,
                ProjectStatus::Cancelled,
            ],

            ProjectStatus::ReadyForPlanning => [
                ProjectStatus::Configuring,
                ProjectStatus::Planning,
                ProjectStatus::Blocked,
                ProjectStatus::Cancelled,
            ],

            ProjectStatus::Planning => [
                ProjectStatus::AwaitingRoadmapApproval,
                ProjectStatus::ReadyForDevelopment,
                ProjectStatus::Blocked,
                ProjectStatus::Cancelled,
            ],

            ProjectStatus::AwaitingRoadmapApproval => [
                ProjectStatus::Planning,
                ProjectStatus::DocumentsPending,
                ProjectStatus::ReadyForDevelopment,
                ProjectStatus::Blocked,
                ProjectStatus::Cancelled,
            ],

            ProjectStatus::ReadyForDevelopment => [
                ProjectStatus::Planning,
                ProjectStatus::Active,
                ProjectStatus::Blocked,
                ProjectStatus::Cancelled,
            ],

            ProjectStatus::Active => [
                ProjectStatus::Paused,
                ProjectStatus::Blocked,
                ProjectStatus::Completed,
                ProjectStatus::Cancelled,
            ],

            ProjectStatus::Paused => [
                ProjectStatus::Active,
                ProjectStatus::Blocked,
                ProjectStatus::Cancelled,
            ],

            /*
             * Returning to Configuring forces all configuration, document,
             * integration, and readiness conditions to be re-evaluated.
             */
            ProjectStatus::Blocked => [
                ProjectStatus::Configuring,
                ProjectStatus::Cancelled,
            ],

            ProjectStatus::Completed,
            ProjectStatus::Cancelled => [],
        };
    }
}
