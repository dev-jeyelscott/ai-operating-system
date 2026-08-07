<?php

declare(strict_types=1);

namespace App\Application\Approvals;

use App\Domain\Approvals\ApprovalStatus;
use App\Domain\Approvals\ApprovalType;
use App\Models\Approval;
use App\Models\MergeDecision;
use App\Models\NotionReconciliationConflict;
use App\Models\Organization;
use App\Models\Project;
use App\Models\QaAssessment;
use App\Models\Roadmap;
use App\Models\RoadmapTask;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Builds one tenant-scoped inbox from existing authoritative decision records.
 *
 * The service never creates another approval source of truth. Each item links
 * to the existing domain-specific command surface that owns the decision.
 */
final readonly class GetProjectApprovalInbox
{
    /**
     * Return pending roadmap, conflict, recovery, and merge decisions.
     *
     * @return array<string, mixed>
     */
    public function handle(
        int $organizationId,
        int $projectId,
        string $category = 'all',
        string $urgency = 'all',
        string $search = '',
    ): array {
        $asOf = CarbonImmutable::now();

        $project = Project::query()
            ->forOrganization($organizationId)
            ->whereKey($projectId)
            ->firstOrFail();

        $organization = Organization::query()
            ->whereKey($organizationId)
            ->firstOrFail();

        $approvals = Approval::query()
            ->forProject($project->id)
            ->where('status', ApprovalStatus::Pending->value)
            ->orderBy('requested_at')
            ->orderBy('id')
            ->get();

        $roadmaps = $this->approvalRoadmaps(
            projectId: $project->id,
            approvals: $approvals,
        );

        $approvalAssessments = $this->approvalAssessments(
            projectId: $project->id,
            approvals: $approvals,
        );

        $conflicts = NotionReconciliationConflict::query()
            ->where('organization_id', $organization->id)
            ->where('project_id', $project->id)
            ->where('state', 'open')
            ->with('mapping.task.roadmap')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $decidedAssessmentIds = MergeDecision::query()
            ->forProject($project->id)
            ->pluck('qa_assessment_id')
            ->filter(
                static fn (mixed $id): bool => is_string($id)
                    && trim($id) !== '',
            )
            ->values()
            ->all();

        $assessmentQuery = QaAssessment::query()
            ->forProject($project->id)
            ->where('status', QaAssessment::STATUS_COMPLETED)
            ->whereNotNull('decision')
            ->with('ticket')
            ->orderBy('created_at')
            ->orderBy('id');

        if ($decidedAssessmentIds !== []) {
            $assessmentQuery->whereNotIn('id', $decidedAssessmentIds);
        }

        $assessments = $assessmentQuery->get();

        $items = [];

        foreach ($approvals as $approval) {
            $items[] = $this->approvalItem(
                organization: $organization,
                project: $project,
                approval: $approval,
                roadmaps: $roadmaps,
                assessments: $approvalAssessments,
                asOf: $asOf,
            );
        }

        foreach ($conflicts as $conflict) {
            $items[] = $this->conflictItem(
                organization: $organization,
                project: $project,
                conflict: $conflict,
            );
        }

        foreach ($assessments as $assessment) {
            $items[] = $this->mergeItem(
                organization: $organization,
                project: $project,
                assessment: $assessment,
            );
        }

        usort(
            $items,
            fn (array $left, array $right): int => $this->compareItems(
                left: $left,
                right: $right,
            ),
        );

        $summary = $this->summary($items);

        $filtered = array_values(array_filter(
            $items,
            fn (array $item): bool => $this->matchesFilters(
                item: $item,
                category: $category,
                urgency: $urgency,
                search: $search,
            ),
        ));

        return [
            'metadata' => [
                'asOf' => $asOf->toIso8601String(),
                'fingerprint' => hash(
                    'sha256',
                    json_encode($filtered, JSON_THROW_ON_ERROR),
                ),
            ],
            'summary' => $summary,
            'items' => $filtered,
        ];
    }

    /**
     * Load only project-owned roadmaps referenced by pending approvals.
     *
     * @param  Collection<int, Approval>  $approvals
     * @return Collection<int, Roadmap>
     */
    private function approvalRoadmaps(
        int $projectId,
        Collection $approvals,
    ): Collection {
        $ids = $approvals
            ->map(
                fn (Approval $approval): ?int => $this->positiveInteger(
                    source: $approval->request_payload,
                    key: 'roadmap_id',
                ),
            )
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return collect();
        }

        return Roadmap::query()
            ->where('project_id', $projectId)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');
    }

    /**
     * Load only project-owned QA assessments referenced by approvals.
     *
     * @param  Collection<int, Approval>  $approvals
     * @return Collection<string, QaAssessment>
     */
    private function approvalAssessments(
        int $projectId,
        Collection $approvals,
    ): Collection {
        $ids = $approvals
            ->map(
                fn (Approval $approval): ?string => $this->stringValue(
                    source: $approval->request_payload,
                    key: 'qa_assessment_id',
                ),
            )
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return collect();
        }

        return QaAssessment::query()
            ->forProject($projectId)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');
    }

    /**
     * Normalize one generic approval record into the shared inbox contract.
     *
     * @param  Collection<int, Roadmap>  $roadmaps
     * @param  Collection<string, QaAssessment>  $assessments
     * @return array<string, mixed>
     */
    private function approvalItem(
        Organization $organization,
        Project $project,
        Approval $approval,
        Collection $roadmaps,
        Collection $assessments,
        CarbonImmutable $asOf,
    ): array {
        $category = match ($approval->type) {
            ApprovalType::Roadmap => 'roadmap',
            ApprovalType::Merge => 'merge',
            ApprovalType::WorkflowTransition,
            ApprovalType::Execution,
            ApprovalType::Recovery => 'recovery',
        };

        [$contextUrl, $contextLabel] = $this->approvalContext(
            organization: $organization,
            project: $project,
            approval: $approval,
            roadmaps: $roadmaps,
            assessments: $assessments,
        );

        $expiresAt = $approval->expires_at;
        $urgency = $this->urgency($expiresAt, $asOf);
        $summary = $this->stringValue(
            source: $approval->request_payload,
            key: 'summary',
        ) ?? $this->approvalSummary($approval->type);

        return [
            'id' => $approval->id,
            'category' => $category,
            'source' => 'approval',
            'type' => $approval->type->value,
            'title' => $this->approvalTitle($approval),
            'summary' => Str::limit($summary, 280),
            'status' => $approval->status->value,
            'requestedAt' => $approval->requested_at->toIso8601String(),
            'expiresAt' => $expiresAt?->toIso8601String(),
            'urgency' => $urgency,
            'overdue' => $urgency === 'overdue',
            'simulated' => $this->stringValue(
                source: $approval->request_payload,
                key: 'execution_provider',
            ) === 'simulation',
            'actualState' => 'pending_human_decision',
            'contextUrl' => $contextUrl,
            'contextLabel' => $contextLabel,
        ];
    }

    /**
     * Normalize one open Notion reconciliation conflict.
     *
     * @return array<string, mixed>
     */
    private function conflictItem(
        Organization $organization,
        Project $project,
        NotionReconciliationConflict $conflict,
    ): array {
        $mapping = $conflict->mapping;
        $ticket = $mapping?->task;
        $roadmap = $ticket?->roadmap;
        $classification = $mapping->reconciliation_state
            ?? 'external_drift';

        $contextUrl = $roadmap instanceof Roadmap
            ? route(
                'organizations.projects.roadmaps.show',
                [
                    'organization' => $organization,
                    'project' => $project,
                    'roadmap' => $roadmap,
                ],
                false,
            )
            : route(
                'organizations.projects.audit.index',
                [
                    'organization' => $organization,
                    'project' => $project,
                ],
                false,
            );

        return [
            'id' => (string) $conflict->id,
            'category' => 'conflict',
            'source' => 'notion_conflict',
            'type' => $classification,
            'title' => $ticket instanceof RoadmapTask
                ? sprintf('%s: %s', $ticket->stable_id, $ticket->title)
                : 'Notion reconciliation conflict',
            'summary' => sprintf(
                '%s requires an explicit reconciliation decision before external task state is trusted.',
                Str::headline($classification),
            ),
            'status' => $conflict->state,
            'requestedAt' => $conflict->created_at->toIso8601String(),
            'expiresAt' => null,
            'urgency' => 'normal',
            'overdue' => false,
            'simulated' => false,
            'actualState' => 'conflicted',
            'contextUrl' => $contextUrl,
            'contextLabel' => 'Resolve conflict',
        ];
    }

    /**
     * Normalize one completed QA assessment with no recorded merge decision.
     *
     * @return array<string, mixed>
     */
    private function mergeItem(
        Organization $organization,
        Project $project,
        QaAssessment $assessment,
    ): array {
        $ticket = $assessment->ticket;

        return [
            'id' => $assessment->id,
            'category' => 'merge',
            'source' => 'qa_assessment',
            'type' => $assessment->decision->value ?? 'human_review_required',
            'title' => $ticket instanceof RoadmapTask
                ? sprintf('%s: %s', $ticket->stable_id, $ticket->title)
                : 'Simulated merge decision',
            'summary' => Str::limit(
                $assessment->recommendation
                    ?? 'Review the independent QA assessment and record a human decision.',
                280,
            ),
            'status' => 'pending',
            'requestedAt' => $assessment->created_at->toIso8601String(),
            'expiresAt' => null,
            'urgency' => 'normal',
            'overdue' => false,
            'simulated' => true,
            'actualState' => $ticket instanceof RoadmapTask
                ? $ticket->actual_state->value
                : 'unverified',
            'contextUrl' => route(
                'organizations.projects.quality-assurance.index',
                [
                    'organization' => $organization,
                    'project' => $project,
                    'assessment' => $assessment->id,
                ],
                false,
            ).'#decision-center',
            'contextLabel' => 'Review merge decision',
        ];
    }

    /**
     * Resolve an approval to its existing domain-specific decision page.
     *
     * @param  Collection<int, Roadmap>  $roadmaps
     * @param  Collection<string, QaAssessment>  $assessments
     * @return array{0: string, 1: string}
     */
    private function approvalContext(
        Organization $organization,
        Project $project,
        Approval $approval,
        Collection $roadmaps,
        Collection $assessments,
    ): array {
        $roadmapId = $this->positiveInteger(
            source: $approval->request_payload,
            key: 'roadmap_id',
        );

        $roadmap = $roadmapId === null
            ? null
            : $roadmaps->get($roadmapId);

        if (
            $approval->type === ApprovalType::Roadmap
            && $roadmap instanceof Roadmap
        ) {
            return [
                route(
                    'organizations.projects.roadmaps.show',
                    [
                        'organization' => $organization,
                        'project' => $project,
                        'roadmap' => $roadmap,
                    ],
                    false,
                ).'#approval',
                'Review roadmap',
            ];
        }

        $assessmentId = $this->stringValue(
            source: $approval->request_payload,
            key: 'qa_assessment_id',
        );

        $assessment = $assessmentId === null
            ? null
            : $assessments->get($assessmentId);

        if (
            $approval->type === ApprovalType::Merge
            && $assessment instanceof QaAssessment
        ) {
            return [
                route(
                    'organizations.projects.quality-assurance.index',
                    [
                        'organization' => $organization,
                        'project' => $project,
                        'assessment' => $assessment->id,
                    ],
                    false,
                ).'#decision-center',
                'Review merge decision',
            ];
        }

        $parameters = [
            'organization' => $organization,
            'project' => $project,
            'approval' => $approval->id,
        ];

        if ($approval->execution_id !== null) {
            $parameters['execution'] = $approval->execution_id;
        }

        return [
            route(
                'organizations.projects.audit.index',
                $parameters,
                false,
            ).'#approval-'.$approval->id,
            'Review decision context',
        ];
    }

    /**
     * Return a concise human-readable approval title.
     */
    private function approvalTitle(Approval $approval): string
    {
        return match ($approval->type) {
            ApprovalType::Roadmap => sprintf(
                'Roadmap revision %s approval',
                $this->positiveInteger(
                    source: $approval->request_payload,
                    key: 'revision',
                ) ?? 'current',
            ),
            ApprovalType::Merge => 'Merge advisory decision',
            ApprovalType::Recovery => 'Recovery action approval',
            ApprovalType::Execution => 'Execution action approval',
            ApprovalType::WorkflowTransition => 'Workflow transition approval',
        };
    }

    /**
     * Return a safe fallback summary for an approval type.
     */
    private function approvalSummary(ApprovalType $type): string
    {
        return match ($type) {
            ApprovalType::Roadmap => 'Review the current roadmap scope, risks, dependencies, and approval evidence.',
            ApprovalType::Merge => 'Review the QA assessment, evidence state, risks, and rollback complexity.',
            ApprovalType::Recovery => 'Review the failed operation and authorize or reject the proposed recovery.',
            ApprovalType::Execution => 'Review the requested execution action before workflow state advances.',
            ApprovalType::WorkflowTransition => 'Review the guarded workflow transition and its supporting evidence.',
        };
    }

    /**
     * Classify expiry urgency without mutating approval state.
     */
    private function urgency(
        ?CarbonImmutable $expiresAt,
        CarbonImmutable $asOf,
    ): string {
        if ($expiresAt === null) {
            return 'normal';
        }

        if ($expiresAt->lessThanOrEqualTo($asOf)) {
            return 'overdue';
        }

        return $expiresAt->lessThanOrEqualTo($asOf->addDay())
            ? 'expiring'
            : 'normal';
    }

    /**
     * Build category and urgency totals before user filtering.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array<string, int>
     */
    private function summary(array $items): array
    {
        $summary = [
            'total' => count($items),
            'roadmap' => 0,
            'conflict' => 0,
            'recovery' => 0,
            'merge' => 0,
            'overdue' => 0,
        ];

        foreach ($items as $item) {
            $category = (string) $item['category'];

            if (array_key_exists($category, $summary)) {
                $summary[$category]++;
            }

            if ($item['overdue'] === true) {
                $summary['overdue']++;
            }
        }

        return $summary;
    }

    /**
     * Apply category, urgency, and text filters to one normalized item.
     *
     * @param  array<string, mixed>  $item
     */
    private function matchesFilters(
        array $item,
        string $category,
        string $urgency,
        string $search,
    ): bool {
        if ($category !== 'all' && $item['category'] !== $category) {
            return false;
        }

        if ($urgency !== 'all' && $item['urgency'] !== $urgency) {
            return false;
        }

        $needle = Str::lower(trim($search));

        if ($needle === '') {
            return true;
        }

        $haystack = Str::lower(implode(' ', [
            (string) $item['title'],
            (string) $item['summary'],
            (string) $item['category'],
            (string) $item['type'],
        ]));

        return str_contains($haystack, $needle);
    }

    /**
     * Sort overdue and expiring decisions before normal oldest-first work.
     *
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private function compareItems(array $left, array $right): int
    {
        $priority = [
            'overdue' => 0,
            'expiring' => 1,
            'normal' => 2,
        ];

        $urgencyComparison = ($priority[(string) $left['urgency']] ?? 3)
            <=> ($priority[(string) $right['urgency']] ?? 3);

        if ($urgencyComparison !== 0) {
            return $urgencyComparison;
        }

        return strcmp(
            (string) $left['requestedAt'],
            (string) $right['requestedAt'],
        );
    }

    /**
     * Return a positive integer from trusted allow-listed payload keys.
     *
     * @param  array<string, mixed>  $source
     */
    private function positiveInteger(array $source, string $key): ?int
    {
        $value = $source[$key] ?? null;

        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && ctype_digit($value)) {
            $integer = (int) $value;

            return $integer > 0 ? $integer : null;
        }

        return null;
    }

    /**
     * Return a trimmed string from trusted allow-listed payload keys.
     *
     * @param  array<string, mixed>  $source
     */
    private function stringValue(array $source, string $key): ?string
    {
        $value = $source[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
