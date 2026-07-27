<?php

declare(strict_types=1);

namespace App\Application\Planning;

use App\Application\Approvals\Commands\DecideApproval;
use App\Application\Shared\Commands\CommandBus;
use App\Application\Shared\Commands\CommandResult;
use App\Application\Shared\Exceptions\ConflictException;
use App\Application\Workflows\Data\WorkflowTransitionContext;
use App\Application\Workflows\TransitionWorkflowInstance;
use App\Domain\Approvals\ApprovalDecision;
use App\Domain\Projects\ProjectStatus;
use App\Models\Approval;
use App\Models\Project;
use App\Models\Roadmap;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

final readonly class DecideRoadmap
{
    public function __construct(
        private CommandBus $commands,
        private MaterializeRoadmap $materializer,
        private TransitionWorkflowInstance $workflowTransitions,
        private RecordRoadmapLifecycleEvent $events,
    ) {}

    public function handle(
        Roadmap $roadmap,
        User $actor,
        ApprovalDecision $decision,
        int $expectedContentVersion,
        string $expectedFingerprint,
        string $idempotencyKey,
        string $correlationId,
        ?string $reason = null,
    ): CommandResult {
        Gate::forUser($actor)->authorize('approve', $roadmap->project);

        return DB::transaction(function () use ($roadmap, $actor, $decision, $expectedContentVersion, $expectedFingerprint, $idempotencyKey, $correlationId, $reason): CommandResult {
            Project::query()->whereKey($roadmap->project_id)->lockForUpdate()->firstOrFail();
            $locked = Roadmap::query()->with(['approval', 'execution.workflowInstance.workflowDefinition', 'edits'])->whereKey($roadmap->id)->lockForUpdate()->firstOrFail();
            $latestId = Roadmap::query()->where('project_id', $locked->project_id)->orderByDesc('revision')->value('id');

            if ($latestId !== $locked->id || $locked->content_version !== $expectedContentVersion || ! hash_equals($locked->candidate_fingerprint, $expectedFingerprint)) {
                throw new ConflictException('The roadmap decision is stale.');
            }
            if ($locked->approval === null || $locked->approval->id !== $locked->approval_id) {
                throw new ConflictException('The roadmap has no current approval request.');
            }
            $this->assertApprovalReferencesCurrentRoadmap($locked->approval, $locked);
            if ($decision === ApprovalDecision::Reject && ($reason === null || trim($reason) === '')) {
                throw new InvalidArgumentException('Rejection feedback is required.');
            }

            $result = $this->commands->dispatch(new DecideApproval(
                approvalId: $locked->approval->id,
                actorUserId: $actor->id,
                decision: $decision,
                idempotencyKey: $idempotencyKey,
                correlationId: $correlationId,
                reason: $reason,
            ));
            if (! $result->isSuccessful()) {
                return $result;
            }

            if ($decision === ApprovalDecision::Approve && $locked->status !== 'approved') {
                $locked->forceFill([
                    'status' => 'approved',
                    'approved_snapshot' => $this->materializer->handle($locked),
                    'approved_fingerprint' => $locked->candidate_fingerprint,
                    'approved_at' => now(),
                ])->save();
                $locked->project->transitionTo(ProjectStatus::ReadyForDevelopment);
                $this->transition($locked, $actor, 'roadmap.approve', 'roadmap.approved', $correlationId);
                $this->events->approved($locked, $actor, $correlationId, 'human');
            }

            if ($decision === ApprovalDecision::Reject && $locked->status !== 'rejected') {
                $locked->forceFill(['status' => 'rejected', 'regeneration_feedback' => trim((string) $reason)])->save();
                $locked->project->transitionTo(ProjectStatus::DocumentsPending);
                $this->transition($locked, $actor, 'roadmap.reject', 'roadmap.rejected', $correlationId);
                $this->events->rejected($locked, $actor, $correlationId);
            }

            return $result;
        }, attempts: 3);
    }

    private function assertApprovalReferencesCurrentRoadmap(Approval $approval, Roadmap $roadmap): void
    {
        $payload = $approval->request_payload;
        $matches = ($payload['roadmap_id'] ?? null) === $roadmap->id
            && ($payload['approval_authority'] ?? null) === 'human'
            && ($payload['revision'] ?? null) === $roadmap->revision
            && ($payload['content_version'] ?? null) === $roadmap->content_version
            && ($payload['candidate_fingerprint'] ?? null) === $roadmap->candidate_fingerprint
            && ($payload['output_fingerprint'] ?? null) === $roadmap->output_fingerprint
            && ($payload['project_context_snapshot_id'] ?? null) === $roadmap->project_context_snapshot_id
            && ($payload['planning_execution_id'] ?? null) === $roadmap->planning_execution_id;
        if (! $matches) {
            throw new ConflictException('The approval request references stale roadmap content.');
        }
    }

    private function transition(Roadmap $roadmap, User $actor, string $transition, string $guard, string $correlationId): void
    {
        $this->workflowTransitions->handle(
            instance: $roadmap->execution->workflowInstance,
            transitionName: $transition,
            context: WorkflowTransitionContext::user(
                userId: $actor->id,
                correlationId: $correlationId,
                executionId: $roadmap->planning_execution_id,
                guardContext: [$guard => true],
            ),
        );
    }
}
