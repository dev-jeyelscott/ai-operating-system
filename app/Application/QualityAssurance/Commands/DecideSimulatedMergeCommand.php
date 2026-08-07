<?php

declare(strict_types=1);

namespace App\Application\QualityAssurance\Commands;

use App\Application\Shared\Idempotency\Contracts\IdempotentCommand;
use App\Domain\QualityAssurance\MergeDecisionAction;

/**
 * Carries one authorized simulated merge-decision request.
 */
final readonly class DecideSimulatedMergeCommand implements IdempotentCommand
{
    /**
     * Store immutable, tenant-scoped command input.
     */
    public function __construct(
        public int $organizationId,
        public int $projectId,
        public string $qaAssessmentId,
        public int $actorUserId,
        public MergeDecisionAction $action,
        public string $expectedAssessmentFingerprint,
        public string $requestIdempotencyKey,
        public string $correlationId,
        public ?string $reason = null,
        public ?string $causationId = null,
    ) {}

    /**
     * Return the caller-supplied replay key.
     */
    public function idempotencyKey(): string
    {
        return $this->requestIdempotencyKey;
    }

    /**
     * Isolate replay protection to one project and assessment operation.
     */
    public function idempotencyScope(): string
    {
        return sprintf(
            'organization:%d:project:%d:qa-assessment:%s:simulated-merge-decision',
            $this->organizationId,
            $this->projectId,
            $this->qaAssessmentId,
        );
    }

    /**
     * Return deterministic, non-secret fingerprint material.
     *
     * @return array<string, mixed>
     */
    public function idempotencyPayload(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'project_id' => $this->projectId,
            'qa_assessment_id' => $this->qaAssessmentId,
            'actor_user_id' => $this->actorUserId,
            'action' => $this->action->value,
            'expected_assessment_fingerprint' => $this->expectedAssessmentFingerprint,
            'reason_fingerprint' => $this->reason === null
                ? null
                : hash('sha256', trim($this->reason)),
        ];
    }
}
