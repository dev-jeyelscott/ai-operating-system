<?php

declare(strict_types=1);

namespace App\Application\Planning;

use App\Domain\Approvals\ApprovalStatus;
use App\Models\Approval;
use App\Models\Roadmap;

final class RoadmapEligibility
{
    public function allowsPublicationOrDevelopment(Roadmap $roadmap): bool
    {
        $roadmap->loadMissing(['approval', 'traceabilityLinks', 'tasks']);
        $isLatest = ! Roadmap::query()
            ->where('project_id', $roadmap->project_id)
            ->where('revision', '>', $roadmap->revision)
            ->exists();
        $fullyCovered = $roadmap->tasks->isNotEmpty()
            && $roadmap->tasks->every(function ($task) use ($roadmap): bool {
                $links = $roadmap->traceabilityLinks->where('roadmap_task_id', $task->id);
                $criteria = collect($task->acceptance_criteria)->pluck('stable_id')->filter()->unique();

                return $criteria->isNotEmpty()
                    && $criteria->every(fn (mixed $criterionId): bool => is_string($criterionId)
                        && $links->where('criterion_stable_id', $criterionId)->isNotEmpty());
            });
        $approved = $roadmap->status === 'approved'
            && $roadmap->approved_fingerprint !== null
            && hash_equals($roadmap->candidate_fingerprint, $roadmap->approved_fingerprint)
            && $roadmap->approved_snapshot !== null
            && hash_equals(
                $roadmap->approved_fingerprint,
                RoadmapCommandFingerprint::make($roadmap->approved_snapshot),
            )
            && $roadmap->approval !== null
            && $this->approvalMatches($roadmap->approval, $roadmap);

        return $isLatest
            && $fullyCovered
            && in_array($roadmap->readiness, ['ready', 'ready_with_risks'], true)
            && $approved;
    }

    private function approvalMatches(Approval $approval, Roadmap $roadmap): bool
    {
        $payload = $approval->request_payload;
        $authority = $payload['approval_authority'] ?? null;

        return $approval->status === ApprovalStatus::Approved
            && in_array($authority, ['human', 'immutable_policy'], true)
            && ($authority !== 'human' || $approval->decided_by_user_id !== null)
            && ($authority !== 'immutable_policy' || $approval->decided_by_user_id === null)
            && ($payload['roadmap_id'] ?? null) === $roadmap->id
            && ($payload['revision'] ?? null) === $roadmap->revision
            && ($payload['content_version'] ?? null) === $roadmap->content_version
            && ($payload['candidate_fingerprint'] ?? null) === $roadmap->candidate_fingerprint
            && ($payload['output_fingerprint'] ?? null) === $roadmap->output_fingerprint
            && ($payload['project_context_snapshot_id'] ?? null) === $roadmap->project_context_snapshot_id
            && ($payload['planning_execution_id'] ?? null) === $roadmap->planning_execution_id;
    }
}
