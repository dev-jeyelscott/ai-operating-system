<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Approvals\ApprovalStatus;
use App\Domain\Approvals\ApprovalType;
use App\Models\Approval;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Approval>
 */
final class ApprovalFactory extends Factory
{
    /**
     * Define one pending project-scoped approval.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'workflow_instance_id' => null,
            'execution_id' => null,
            'type' => ApprovalType::WorkflowTransition,
            'status' => ApprovalStatus::Pending,
            'requested_by_user_id' => null,
            'decided_by_user_id' => null,
            'request_idempotency_key' => sprintf(
                'approval-request:%s',
                Str::ulid(),
            ),
            'request_fingerprint' => hash(
                'sha256',
                (string) Str::ulid(),
            ),
            'decision_idempotency_key' => null,
            'decision_fingerprint' => null,
            'request_payload' => [
                'summary' => fake()->sentence(),
            ],
            'decision_reason' => null,
            'requested_at' => now(),
            'expires_at' => now()->addHour(),
            'decided_at' => null,
        ];
    }

    /**
     * Mark the approval as approved by a user.
     */
    public function approved(): static
    {
        return $this->state(fn (): array => [
            'status' => ApprovalStatus::Approved,
            'decided_by_user_id' => User::factory(),
            'decision_idempotency_key' => sprintf(
                'approval-decision:%s',
                Str::ulid(),
            ),
            'decision_fingerprint' => hash(
                'sha256',
                (string) Str::ulid(),
            ),
            'decision_reason' => 'Approved by test fixture.',
            'decided_at' => now(),
        ]);
    }

    /**
     * Mark the approval as rejected by a user.
     */
    public function rejected(): static
    {
        return $this->state(fn (): array => [
            'status' => ApprovalStatus::Rejected,
            'decided_by_user_id' => User::factory(),
            'decision_idempotency_key' => sprintf(
                'approval-decision:%s',
                Str::ulid(),
            ),
            'decision_fingerprint' => hash(
                'sha256',
                (string) Str::ulid(),
            ),
            'decision_reason' => 'Rejected by test fixture.',
            'decided_at' => now(),
        ]);
    }

    /**
     * Mark the approval as expired without a human decision.
     */
    public function expired(): static
    {
        return $this->state(fn (): array => [
            'status' => ApprovalStatus::Expired,
            'expires_at' => now()->subMinute(),
            'decided_by_user_id' => null,
            'decision_idempotency_key' => null,
            'decision_fingerprint' => null,
            'decision_reason' => null,
            'decided_at' => null,
        ]);
    }
}
