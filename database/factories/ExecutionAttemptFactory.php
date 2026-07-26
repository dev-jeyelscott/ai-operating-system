<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Executions\ExecutionAttemptStatus;
use App\Domain\Projects\Configuration\ReasoningLevel;
use App\Models\Execution;
use App\Models\ExecutionAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExecutionAttempt>
 */
final class ExecutionAttemptFactory extends Factory
{
    /**
     * Define one queued deterministic simulation attempt.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'execution_id' => Execution::factory(),
            'attempt_number' => 1,
            'status' => ExecutionAttemptStatus::Queued,
            'execution_provider' => 'simulation',
            'model_identifier' => null,
            'requested_reasoning_level' => ReasoningLevel::Medium,
            'effective_reasoning_level' => ReasoningLevel::Medium,
            'reasoning_resolution_source' => 'project_default',
            'reasoning_escalation_reason' => null,
            'simulation_mode' => 'deterministic',
            'simulation_seed' => 'default',
            'reported_state' => null,
            'observed_state' => null,
            'actual_state' => null,
            'confidence' => null,
            'estimated_cost' => null,
            'actual_cost' => null,
            'cost_currency' => null,
            'error_code' => null,
            'error_message' => null,
            'started_at' => null,
            'finished_at' => null,
        ];
    }

    /**
     * Mark the attempt as currently running.
     */
    public function running(): static
    {
        return $this->state(fn (): array => [
            'status' => ExecutionAttemptStatus::Running,
            'started_at' => now(),
            'finished_at' => null,
        ]);
    }

    /**
     * Mark the attempt as successfully completed.
     */
    public function completed(): static
    {
        return $this->state(function (): array {
            $startedAt = now()->subSecond();

            return [
                'status' => ExecutionAttemptStatus::Completed,
                'reported_state' => 'completed',
                'observed_state' => 'simulated_output_recorded',
                'actual_state' => 'unverified',
                'confidence' => '0.9000',
                'started_at' => $startedAt,
                'finished_at' => now(),
            ];
        });
    }

    /**
     * Mark the attempt as failed with safe fixture error content.
     */
    public function failed(): static
    {
        return $this->state(function (): array {
            $startedAt = now()->subSecond();

            return [
                'status' => ExecutionAttemptStatus::Failed,
                'error_code' => 'provider.failed',
                'error_message' => 'The provider attempt failed.',
                'started_at' => $startedAt,
                'finished_at' => now(),
            ];
        });
    }
}
