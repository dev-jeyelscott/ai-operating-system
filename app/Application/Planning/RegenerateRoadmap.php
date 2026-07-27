<?php

declare(strict_types=1);

namespace App\Application\Planning;

use App\Application\Approvals\RecordApprovalLifecycleEvent;
use App\Application\Shared\Exceptions\ConflictException;
use App\Application\Workflows\Data\WorkflowTransitionContext;
use App\Application\Workflows\TransitionWorkflowInstance;
use App\Domain\Approvals\ApprovalStatus;
use App\Domain\Projects\ProjectStatus;
use App\Models\Execution;
use App\Models\Project;
use App\Models\Roadmap;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

final readonly class RegenerateRoadmap
{
    public function __construct(
        private TransitionWorkflowInstance $workflowTransitions,
        private RecordApprovalLifecycleEvent $approvalEvents,
        private RecordRoadmapLifecycleEvent $roadmapEvents,
    ) {}

    public function handle(Roadmap $roadmap, User $actor, int $expectedContentVersion, string $expectedFingerprint, string $feedback, string $idempotencyKey, string $correlationId): Execution
    {
        Gate::forUser($actor)->authorize('approve', $roadmap->project);
        $normalizedFeedback = trim($feedback);
        if ($normalizedFeedback === '') {
            throw new InvalidArgumentException('Regeneration feedback is required.');
        }
        $feedbackFingerprint = hash('sha256', $normalizedFeedback);

        return DB::transaction(function () use ($roadmap, $actor, $expectedContentVersion, $expectedFingerprint, $normalizedFeedback, $feedbackFingerprint, $idempotencyKey, $correlationId): Execution {
            Project::query()->whereKey($roadmap->project_id)->lockForUpdate()->firstOrFail();
            $locked = Roadmap::query()->with(['approval', 'execution.workflowInstance.workflowDefinition'])->whereKey($roadmap->id)->lockForUpdate()->firstOrFail();
            $executionHash = hash('sha256', implode('|', [
                $locked->project_id,
                $locked->id,
                $feedbackFingerprint,
                hash('sha256', $idempotencyKey),
            ]));
            $existing = Execution::query()->forProject($locked->project_id)->where('idempotency_key', $executionHash)->first();
            if ($existing !== null) {
                return $existing;
            }

            $latestId = Roadmap::query()->where('project_id', $locked->project_id)->orderByDesc('revision')->value('id');
            if ($latestId !== $locked->id || $locked->content_version !== $expectedContentVersion || ! hash_equals($locked->candidate_fingerprint, $expectedFingerprint)) {
                throw new ConflictException('The regeneration request is stale.');
            }
            if ($locked->status !== 'awaiting_approval') {
                throw new ConflictException('Only the current undecided roadmap may be regenerated.');
            }

            $source = $locked->execution;
            $this->workflowTransitions->handle(
                instance: $source->workflowInstance,
                transitionName: 'roadmap.regenerate',
                context: WorkflowTransitionContext::user(
                    userId: $actor->id,
                    correlationId: $correlationId,
                    executionId: $source->id,
                    guardContext: ['roadmap.regeneration_requested' => true],
                ),
            );

            $newExecution = Execution::query()->create([
                'project_id' => $source->project_id,
                'workflow_instance_id' => $source->workflow_instance_id,
                'project_context_snapshot_id' => $source->project_context_snapshot_id,
                'capability' => $source->capability,
                'logical_role' => $source->logical_role,
                'requested_reasoning_level' => $source->requested_reasoning_level,
                'retry_limit' => $source->retry_limit,
                'timeout_seconds' => $source->timeout_seconds,
                'retry_base_delay_seconds' => $source->retry_base_delay_seconds,
                'retry_max_delay_seconds' => $source->retry_max_delay_seconds,
                'retry_jitter_percent' => $source->retry_jitter_percent,
                'correlation_id' => $correlationId,
                'idempotency_key' => $executionHash,
            ]);

            $locked->forceFill([
                'status' => 'superseded',
                'regeneration_feedback' => $normalizedFeedback,
                'feedback_fingerprint' => $feedbackFingerprint,
            ])->save();
            if ($locked->approval !== null && $locked->approval->status === ApprovalStatus::Pending) {
                $locked->approval->forceFill(['status' => ApprovalStatus::Expired])->save();
                $this->approvalEvents->expired($locked->approval, $correlationId, null, 'superseded_by_regeneration');
            }
            $locked->project->transitionTo(ProjectStatus::Planning);
            $this->roadmapEvents->regenerationRequested($locked, $newExecution, $actor, $correlationId);

            return $newExecution;
        }, attempts: 3);
    }
}
