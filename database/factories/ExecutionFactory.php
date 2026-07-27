<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Executions\ExecutionStatus;
use App\Domain\Projects\Configuration\ReasoningLevel;
use App\Models\Execution;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Execution>
 */
final class ExecutionFactory extends Factory
{
    /**
     * Define one queued logical execution.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'workflow_instance_id' => null,
            'capability' => 'planning',
            'logical_role' => 'project_manager',
            'status' => ExecutionStatus::Queued,
            'requested_reasoning_level' => ReasoningLevel::Medium,
            'attempt_count' => 0,
            'retry_limit' => 3,
            'timeout_seconds' => 900,
            'retry_base_delay_seconds' => 30,
            'retry_max_delay_seconds' => 900,
            'retry_jitter_percent' => 20,
            'correlation_id' => (string) Str::ulid(),
            'idempotency_key' => sprintf(
                'execution:%s',
                Str::ulid(),
            ),
            'next_attempt_at' => null,
            'cancel_requested_at' => null,
            'cancelled_at' => null,
            'cancellation_reason' => null,
            'started_at' => null,
            'finished_at' => null,
        ];
    }

    /**
     * Mark the execution as currently running.
     */
    public function running(): static
    {
        return $this->state(fn (): array => [
            'status' => ExecutionStatus::Running,
            'started_at' => now(),
            'finished_at' => null,
        ]);
    }

    /**
     * Mark the execution as successfully completed.
     */
    public function completed(): static
    {
        return $this->state(function (): array {
            $startedAt = now()->subSecond();

            return [
                'status' => ExecutionStatus::Completed,
                'started_at' => $startedAt,
                'finished_at' => now(),
            ];
        });
    }
}
