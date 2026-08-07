<?php

declare(strict_types=1);

namespace App\Application\Notifications;

use App\Domain\Notifications\NotificationActionType;
use App\Models\Approval;
use App\Models\Execution;
use App\Models\NotificationEvent;
use App\Models\Organization;
use App\Models\Project;
use App\Models\QaAssessment;
use App\Models\Roadmap;
use App\Models\RoadmapTask;
use Illuminate\Database\Eloquent\Builder;

/**
 * Resolves trusted notification metadata into internal named application routes.
 *
 * Raw persisted URLs are deliberately ignored. This prevents notification
 * records from becoming an open-redirect mechanism.
 */
final readonly class ResolveNotificationDeepLink
{
    /**
     * Resolve the safest available application context for a notification.
     */
    public function handle(NotificationEvent $event): string
    {
        $organization = Organization::query()
            ->whereKey($event->organization_id)
            ->firstOrFail();

        $project = $event->project_id === null
            ? null
            : Project::query()
                ->forOrganization($organization->id)
                ->whereKey($event->project_id)
                ->first();

        $action = $this->actionMetadata($event);
        $typeValue = $this->stringValue($action, 'type');
        $type = $typeValue === null
            ? null
            : NotificationActionType::tryFrom($typeValue);

        if ($project === null || $type === null) {
            return $this->fallbackUrl($organization, $project);
        }

        return match ($type) {
            NotificationActionType::Approval => $this->resolveApproval(
                organization: $organization,
                project: $project,
                action: $action,
            ),

            NotificationActionType::Execution => $this->resolveExecution(
                organization: $organization,
                project: $project,
                event: $event,
                action: $action,
            ),

            NotificationActionType::Blocker => $this->resolveBlocker(
                organization: $organization,
                project: $project,
                action: $action,
            ),

            NotificationActionType::Decision => $this->resolveDecision(
                organization: $organization,
                project: $project,
                action: $action,
            ),
        };
    }

    /**
     * Resolve a pending approval to its closest authoritative context.
     *
     * Roadmap approvals open the roadmap. Other approval types fall back to
     * the project audit timeline until AIOS-121 introduces the approval inbox.
     *
     * @param  array<string, mixed>  $action
     */
    private function resolveApproval(
        Organization $organization,
        Project $project,
        array $action,
    ): string {
        $approvalId = $this->stringValue($action, 'approval_id');

        if ($approvalId === null) {
            return $this->fallbackUrl($organization, $project);
        }

        $approval = Approval::query()
            ->forProject($project->id)
            ->whereKey($approvalId)
            ->first();

        if (! $approval instanceof Approval) {
            return $this->fallbackUrl($organization, $project);
        }

        $roadmapId = $this->positiveInteger($action, 'roadmap_id')
            ?? $this->positiveInteger(
                $approval->request_payload,
                'roadmap_id',
            );

        if ($roadmapId !== null) {
            $roadmap = Roadmap::query()
                ->where('project_id', $project->id)
                ->whereKey($roadmapId)
                ->first();

            if ($roadmap instanceof Roadmap) {
                return route(
                    'organizations.projects.roadmaps.show',
                    [
                        'organization' => $organization,
                        'project' => $project,
                        'roadmap' => $roadmap,
                    ],
                    false,
                ).'#approval';
            }
        }

        return route(
            'organizations.projects.audit.index',
            [
                'organization' => $organization,
                'project' => $project,
                'approval' => $approval->id,
            ],
            false,
        ).'#approval-'.$approval->id;
    }

    /**
     * Resolve an execution to the appropriate layer-specific inspector.
     *
     * @param  array<string, mixed>  $action
     */
    private function resolveExecution(
        Organization $organization,
        Project $project,
        NotificationEvent $event,
        array $action,
    ): string {
        $executionId = $this->stringValue($action, 'execution_id')
            ?? $event->execution_id;

        if ($executionId === null) {
            return $this->fallbackUrl($organization, $project);
        }

        $execution = Execution::query()
            ->forProject($project->id)
            ->whereKey($executionId)
            ->first();

        if (! $execution instanceof Execution) {
            return $this->fallbackUrl($organization, $project);
        }

        $capability = strtolower($execution->capability);

        if (str_contains($capability, 'planning')) {
            $roadmap = Roadmap::query()
                ->where('project_id', $project->id)
                ->where('planning_execution_id', $execution->id)
                ->latest('revision')
                ->first();

            if ($roadmap instanceof Roadmap) {
                return route(
                    'organizations.projects.roadmaps.show',
                    [
                        'organization' => $organization,
                        'project' => $project,
                        'roadmap' => $roadmap,
                    ],
                    false,
                );
            }

            return route(
                'organizations.projects.roadmaps.index',
                [
                    'organization' => $organization,
                    'project' => $project,
                ],
                false,
            );
        }

        if (
            str_contains($capability, 'quality_assurance')
            || str_contains($capability, 'review')
        ) {
            $assessment = QaAssessment::query()
                ->forProject($project->id)
                ->where('review_execution_id', $execution->id)
                ->latest('created_at')
                ->first();

            return route(
                'organizations.projects.quality-assurance.index',
                array_filter([
                    'organization' => $organization,
                    'project' => $project,
                    'assessment' => $assessment?->id,
                ]),
                false,
            ).'#assessment';
        }

        if (
            str_contains($capability, 'development')
            || str_contains($capability, 'implementation')
        ) {
            return route(
                'organizations.projects.development.executions.show',
                [
                    'organization' => $organization,
                    'project' => $project,
                    'execution' => $execution,
                ],
                false,
            );
        }

        return route(
            'organizations.projects.audit.index',
            [
                'organization' => $organization,
                'project' => $project,
                'execution' => $execution->id,
            ],
            false,
        ).'#execution-'.$execution->id;
    }

    /**
     * Resolve a blocker notification to the exact affected roadmap ticket.
     *
     * @param  array<string, mixed>  $action
     */
    private function resolveBlocker(
        Organization $organization,
        Project $project,
        array $action,
    ): string {
        $taskId = $this->positiveInteger($action, 'roadmap_task_id');
        $stableId = $this->stringValue($action, 'ticket_id');

        if ($taskId === null && $stableId === null) {
            return $this->fallbackUrl($organization, $project);
        }

        $query = RoadmapTask::query()
            ->whereHas(
                'roadmap',
                static function (Builder $query) use ($project): void {
                    $query->where('project_id', $project->id);
                },
            )
            ->with('roadmap');

        if ($taskId !== null) {
            $query->whereKey($taskId);
        } else {
            $query->where('stable_id', $stableId);
        }

        $ticket = $query->first();

        if (! $ticket instanceof RoadmapTask) {
            return $this->fallbackUrl($organization, $project);
        }

        $roadmap = $ticket->getRelation('roadmap');

        if (! $roadmap instanceof Roadmap) {
            return $this->fallbackUrl($organization, $project);
        }

        return route(
            'organizations.projects.roadmaps.tasks.show',
            [
                'organization' => $organization,
                'project' => $project,
                'roadmap' => $roadmap,
                'task' => $ticket,
            ],
            false,
        ).'#blocker';
    }

    /**
     * Resolve a merge-decision notification to the selected QA assessment.
     *
     * @param  array<string, mixed>  $action
     */
    private function resolveDecision(
        Organization $organization,
        Project $project,
        array $action,
    ): string {
        $assessmentId = $this->stringValue(
            $action,
            'qa_assessment_id',
        );

        if ($assessmentId === null) {
            return $this->fallbackUrl($organization, $project);
        }

        $assessment = QaAssessment::query()
            ->forProject($project->id)
            ->whereKey($assessmentId)
            ->first();

        if (! $assessment instanceof QaAssessment) {
            return $this->fallbackUrl($organization, $project);
        }

        return route(
            'organizations.projects.quality-assurance.index',
            [
                'organization' => $organization,
                'project' => $project,
                'assessment' => $assessment->id,
            ],
            false,
        ).'#decision-center';
    }

    /**
     * Return the safe project or organization landing page.
     */
    private function fallbackUrl(
        Organization $organization,
        ?Project $project,
    ): string {
        if ($project instanceof Project) {
            return route(
                'organizations.projects.show',
                [
                    'organization' => $organization,
                    'project' => $project,
                ],
                false,
            );
        }

        return route(
            'organizations.dashboard',
            $organization,
            false,
        );
    }

    /**
     * Return normalized notification action metadata.
     *
     * @return array<string, mixed>
     */
    private function actionMetadata(NotificationEvent $event): array
    {
        $action = $event->data['action'] ?? null;

        return is_array($action) ? $action : [];
    }

    /**
     * Return a non-empty trimmed string from an action payload.
     *
     * @param  array<string, mixed>  $source
     */
    private function stringValue(
        array $source,
        string $key,
    ): ?string {
        $value = $source[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    /**
     * Return a positive integer from an action payload.
     *
     * @param  array<string, mixed>  $source
     */
    private function positiveInteger(
        array $source,
        string $key,
    ): ?int {
        $value = $source[$key] ?? null;

        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (
            is_string($value)
            && ctype_digit($value)
            && (int) $value > 0
        ) {
            return (int) $value;
        }

        return null;
    }
}
