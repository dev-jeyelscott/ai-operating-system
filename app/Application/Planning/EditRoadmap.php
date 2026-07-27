<?php

declare(strict_types=1);

namespace App\Application\Planning;

use App\Application\Approvals\Commands\RequestApproval;
use App\Application\Approvals\RecordApprovalLifecycleEvent;
use App\Application\Shared\Commands\CommandBus;
use App\Application\Shared\Exceptions\ConflictException;
use App\Domain\Approvals\ApprovalStatus;
use App\Domain\Approvals\ApprovalType;
use App\Models\Approval;
use App\Models\Project;
use App\Models\Roadmap;
use App\Models\RoadmapEdit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

final readonly class EditRoadmap
{
    private const ROADMAP_FIELDS = ['goal', 'scope', 'assumptions', 'constraints', 'definition_of_done'];

    private const TASK_FIELDS = ['title', 'objective', 'scope', 'acceptance_criteria', 'evidence_requirements', 'priority', 'risk', 'reasoning', 'logical_agent', 'estimated_complexity', 'human_approval_required'];

    public function __construct(
        private MaterializeRoadmap $materializer,
        private CommandBus $commands,
        private RecordApprovalLifecycleEvent $approvalEvents,
        private RecordRoadmapLifecycleEvent $roadmapEvents,
    ) {}

    /** @param array<string, mixed> $patch */
    public function handle(Roadmap $roadmap, User $actor, int $expectedContentVersion, string $expectedFingerprint, array $patch, string $idempotencyKey, string $correlationId): Roadmap
    {
        Gate::forUser($actor)->authorize('update', $roadmap->project);
        $normalizedPatch = $this->normalizePatch($patch);
        $idempotencyKeyHash = hash('sha256', $idempotencyKey);
        $requestFingerprint = RoadmapCommandFingerprint::make([
            'actor_user_id' => $actor->id,
            'expected_content_version' => $expectedContentVersion,
            'expected_fingerprint' => $expectedFingerprint,
            'patch' => $normalizedPatch,
        ]);

        return DB::transaction(function () use ($roadmap, $actor, $expectedContentVersion, $expectedFingerprint, $normalizedPatch, $idempotencyKeyHash, $requestFingerprint, $correlationId): Roadmap {
            Project::query()->whereKey($roadmap->project_id)->lockForUpdate()->firstOrFail();
            $locked = Roadmap::query()->with(['approval', 'execution', 'contextSnapshot', 'edits'])->whereKey($roadmap->id)->lockForUpdate()->firstOrFail();
            $existingEdit = RoadmapEdit::query()
                ->where('roadmap_id', $locked->id)
                ->where('idempotency_key_hash', $idempotencyKeyHash)
                ->first();
            if ($existingEdit !== null) {
                if (! hash_equals($existingEdit->request_fingerprint, $requestFingerprint)) {
                    throw new ConflictException('The roadmap edit idempotency key was already used with different content.');
                }

                return $locked->fresh(['edits', 'approval']);
            }
            $latestId = Roadmap::query()->where('project_id', $locked->project_id)->orderByDesc('revision')->value('id');

            if ($latestId !== $locked->id || $locked->content_version !== $expectedContentVersion || ! hash_equals($locked->candidate_fingerprint, $expectedFingerprint)) {
                throw new ConflictException('The roadmap edit is stale.');
            }
            if (! in_array($locked->status, ['generated', 'awaiting_approval'], true)) {
                throw new ConflictException('Only an undecided roadmap may be edited.');
            }

            $taskIds = $locked->tasks()->pluck('stable_id')->all();
            foreach (array_keys($normalizedPatch['tasks']) as $stableId) {
                if (! in_array($stableId, $taskIds, true)) {
                    throw new ConflictException('A task edit references a task outside this roadmap.');
                }
            }

            $candidate = $this->materializer->apply($this->materializer->handle($locked), $normalizedPatch);
            $fingerprint = RoadmapCommandFingerprint::make($candidate);
            $nextVersion = $locked->content_version + 1;

            RoadmapEdit::query()->create([
                'roadmap_id' => $locked->id,
                'actor_user_id' => $actor->id,
                'base_content_version' => $locked->content_version,
                'content_version' => $nextVersion,
                'patch' => $normalizedPatch,
                'resulting_fingerprint' => $fingerprint,
                'idempotency_key_hash' => $idempotencyKeyHash,
                'request_fingerprint' => $requestFingerprint,
            ]);
            $locked->forceFill(['content_version' => $nextVersion, 'candidate_fingerprint' => $fingerprint])->save();

            $result = $this->commands->dispatch(new RequestApproval(
                projectId: $locked->project_id,
                type: ApprovalType::Roadmap,
                idempotencyKey: sprintf('roadmap-edit-gate:%d:%d:%s', $locked->id, $nextVersion, $idempotencyKeyHash),
                correlationId: $correlationId,
                workflowInstanceId: $locked->execution->workflow_instance_id,
                executionId: $locked->planning_execution_id,
                requestedByUserId: $actor->id,
                payload: [
                    'approval_authority' => 'human',
                    'roadmap_id' => $locked->id,
                    'revision' => $locked->revision,
                    'content_version' => $nextVersion,
                    'candidate_fingerprint' => $fingerprint,
                    'output_fingerprint' => $locked->output_fingerprint,
                    'project_context_snapshot_id' => $locked->project_context_snapshot_id,
                    'planning_execution_id' => $locked->planning_execution_id,
                    'configuration_version_id' => $locked->contextSnapshot->project_configuration_version_id,
                ],
            ));
            if (! $result->isSuccessful()) {
                throw new InvalidArgumentException('The edited roadmap approval request could not be created.');
            }

            $approval = Approval::query()->whereKey($result->data['approval_id'])->firstOrFail();
            if ($locked->approval !== null && $locked->approval->status === ApprovalStatus::Pending) {
                $locked->approval->forceFill(['status' => ApprovalStatus::Expired])->save();
                $this->approvalEvents->expired($locked->approval, $correlationId, null, 'superseded_by_roadmap_edit');
            }
            $locked->forceFill(['approval_id' => $approval->id, 'status' => 'awaiting_approval'])->save();
            $this->roadmapEvents->edited($locked, $actor, $correlationId);

            return $locked->fresh(['edits', 'approval']);
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>
     */
    private function normalizePatch(array $patch): array
    {
        $roadmap = $patch['roadmap'] ?? [];
        $tasks = $patch['tasks'] ?? [];
        if (! is_array($roadmap) || ! is_array($tasks) || ($roadmap === [] && $tasks === [])) {
            throw new InvalidArgumentException('A roadmap edit patch is required.');
        }

        foreach (array_keys($roadmap) as $field) {
            if (! is_string($field) || ! in_array($field, self::ROADMAP_FIELDS, true)) {
                throw new InvalidArgumentException('The roadmap edit contains a non-editable field.');
            }
        }
        foreach ($tasks as $stableId => $taskPatch) {
            if (! is_string($stableId) || ! is_array($taskPatch)) {
                throw new InvalidArgumentException('Task edits must be keyed by stable task ID.');
            }
            foreach (array_keys($taskPatch) as $field) {
                if (! is_string($field) || ! in_array($field, self::TASK_FIELDS, true)) {
                    throw new InvalidArgumentException('The task edit contains a non-editable field.');
                }
            }
            $criteria = $taskPatch['acceptance_criteria'] ?? null;
            if ($criteria !== null) {
                if (! is_array($criteria) || $criteria === []) {
                    throw new InvalidArgumentException('Acceptance-criterion edits must contain stable IDs and descriptions.');
                }
                foreach ($criteria as $criterionStableId => $description) {
                    if (
                        ! is_string($criterionStableId)
                        || preg_match('/\A[a-z][a-z0-9-]{1,99}\z/', $criterionStableId) !== 1
                        || ! is_string($description)
                        || trim($description) === ''
                    ) {
                        throw new InvalidArgumentException('Acceptance-criterion edits must contain valid stable IDs and descriptions.');
                    }
                }
            }
        }

        return ['roadmap' => $roadmap, 'tasks' => $tasks];
    }
}
