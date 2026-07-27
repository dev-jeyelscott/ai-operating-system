<?php

declare(strict_types=1);

namespace App\Application\Planning;

use App\Application\Approvals\RecordApprovalLifecycleEvent;
use App\Application\Shared\Exceptions\ConflictException;
use App\Domain\Approvals\ApprovalStatus;
use App\Models\Approval;
use App\Models\Project;
use App\Models\Roadmap;
use Illuminate\Support\Facades\DB;

final readonly class GrantRoadmapApprovalByPolicy
{
    public function __construct(
        private MaterializeRoadmap $materializer,
        private RecordApprovalLifecycleEvent $approvalEvents,
        private RecordRoadmapLifecycleEvent $roadmapEvents,
    ) {}

    public function handle(Roadmap $roadmap, Approval $approval, string $correlationId): Roadmap
    {
        return DB::transaction(function () use ($roadmap, $approval, $correlationId): Roadmap {
            Project::query()->whereKey($roadmap->project_id)->lockForUpdate()->firstOrFail();
            $lockedRoadmap = Roadmap::query()->with('edits')->whereKey($roadmap->id)->lockForUpdate()->firstOrFail();
            $lockedApproval = Approval::query()->whereKey($approval->id)->lockForUpdate()->firstOrFail();
            $this->assertCurrentPolicyGate($lockedRoadmap, $lockedApproval);

            if (
                $lockedApproval->status === ApprovalStatus::Approved
                && $lockedRoadmap->status === 'approved'
                && $lockedRoadmap->approved_fingerprint !== null
                && hash_equals($lockedRoadmap->candidate_fingerprint, $lockedRoadmap->approved_fingerprint)
            ) {
                return $lockedRoadmap;
            }
            if ($lockedApproval->status !== ApprovalStatus::Pending) {
                throw new ConflictException('The roadmap policy approval is no longer pending.');
            }

            $decisionKey = sprintf('roadmap-policy:%d:%d', $lockedRoadmap->id, $lockedRoadmap->content_version);
            $decisionFingerprint = RoadmapCommandFingerprint::make([
                'approval_id' => $lockedApproval->id,
                'roadmap_id' => $lockedRoadmap->id,
                'content_version' => $lockedRoadmap->content_version,
                'candidate_fingerprint' => $lockedRoadmap->candidate_fingerprint,
                'authority' => 'immutable_policy',
            ]);
            $lockedApproval->forceFill([
                'status' => ApprovalStatus::Approved,
                'decision_idempotency_key' => $decisionKey,
                'decision_fingerprint' => $decisionFingerprint,
                'decision_reason' => 'Approved by the immutable roadmap approval policy waiver.',
                'decided_at' => now(),
            ])->save();
            $lockedRoadmap->forceFill([
                'approval_id' => $lockedApproval->id,
                'status' => 'approved',
                'approved_snapshot' => $this->materializer->handle($lockedRoadmap),
                'approved_fingerprint' => $lockedRoadmap->candidate_fingerprint,
                'approved_at' => now(),
            ])->save();

            $this->approvalEvents->policyGranted($lockedApproval, $correlationId, null);
            $this->roadmapEvents->approved($lockedRoadmap, null, $correlationId, 'immutable_policy');

            return $lockedRoadmap;
        }, attempts: 3);
    }

    private function assertCurrentPolicyGate(Roadmap $roadmap, Approval $approval): void
    {
        $payload = $approval->request_payload;
        if (
            $approval->project_id !== $roadmap->project_id
            || $approval->execution_id !== $roadmap->planning_execution_id
            || ($payload['approval_authority'] ?? null) !== 'immutable_policy'
            || ($payload['roadmap_id'] ?? null) !== $roadmap->id
            || ($payload['revision'] ?? null) !== $roadmap->revision
            || ($payload['content_version'] ?? null) !== $roadmap->content_version
            || ($payload['candidate_fingerprint'] ?? null) !== $roadmap->candidate_fingerprint
            || ($payload['output_fingerprint'] ?? null) !== $roadmap->output_fingerprint
            || ($payload['project_context_snapshot_id'] ?? null) !== $roadmap->project_context_snapshot_id
            || ($payload['planning_execution_id'] ?? null) !== $roadmap->planning_execution_id
        ) {
            throw new ConflictException('The policy approval request references stale roadmap content.');
        }
    }
}
