<?php

declare(strict_types=1);

namespace App\Application\QualityAssurance;

use App\Domain\Executions\ExecutionAttemptStatus;
use App\Domain\Executions\ExecutionCapability;
use App\Domain\Executions\ExecutionStatus;
use App\Domain\Projects\Configuration\ReasoningLevel;
use App\Models\Execution;
use App\Models\ExecutionAttempt;
use App\Models\Project;
use App\Models\QaAssessment;
use App\Models\RoadmapTask;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Creates one idempotent and independent Layer 3 execution for an eligible ticket.
 */
final readonly class StartQualityAssuranceExecution
{
    public function __construct(
        private BuildForQaTicketEligibilityContext $contexts,
        private ForQaTicketEligibilityEvaluator $eligibility,
        private Layer3RoleIndependencePolicy $independence,
    ) {}

    /**
     * Create or return the QA assessment linked to one completed implementation.
     */
    public function handle(
        int $organizationId,
        int $projectId,
        int $roadmapTaskId,
        string $implementationExecutionId,
        int $implementationAttemptId,
        string $scenario = 'happy_path',
        int $seed = 106,
    ): QaAssessment {
        return DB::transaction(
            function () use (
                $organizationId,
                $projectId,
                $roadmapTaskId,
                $implementationExecutionId,
                $implementationAttemptId,
                $scenario,
                $seed,
            ): QaAssessment {
                $project = Project::query()
                    ->forOrganization($organizationId)
                    ->whereKey($projectId)
                    ->lock('for share')
                    ->firstOrFail();

                $ticket = RoadmapTask::query()
                    ->whereKey($roadmapTaskId)
                    ->whereHas(
                        'roadmap',
                        static fn($query) => $query->where(
                            'project_id',
                            $project->id,
                        ),
                    )
                    ->lockForUpdate()
                    ->firstOrFail();

                $ticket->load('roadmap');

                $implementationExecution = Execution::query()
                    ->forProject($project->id)
                    ->whereKey($implementationExecutionId)
                    ->whereIn('capability', [
                        'development',
                        'development.execute',
                    ])
                    ->where('status', ExecutionStatus::Completed)
                    ->lock('for share')
                    ->firstOrFail();

                $implementationAttempt = ExecutionAttempt::query()
                    ->where(
                        'execution_id',
                        $implementationExecution->id,
                    )
                    ->whereKey($implementationAttemptId)
                    ->where(
                        'status',
                        ExecutionAttemptStatus::Completed,
                    )
                    ->lock('for share')
                    ->firstOrFail();

                $context = $this->contexts->build(
                    ticket: $ticket,
                    implementationExecution: $implementationExecution,
                    implementationAttempt: $implementationAttempt,
                );

                $eligibility = $this->eligibility->evaluate(
                    $context,
                );

                if (! $eligibility->isEligible()) {
                    throw new LogicException(sprintf(
                        'Ticket is not eligible for Layer 3: %s',
                        implode(', ', $eligibility->reasonValues()),
                    ));
                }

                $existing = QaAssessment::query()
                    ->forProject($project->id)
                    ->where('roadmap_task_id', $ticket->id)
                    ->where(
                        'implementation_execution_id',
                        $implementationExecution->id,
                    )
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    return $existing;
                }

                /*
                 * Final QA defaults to High reasoning. The immutable execution
                 * retains this decision for every later provider attempt.
                 */
                $reviewExecution = Execution::query()->create([
                    'project_id' => $project->id,
                    'workflow_instance_id' => $implementationExecution
                        ->workflow_instance_id,
                    'project_context_snapshot_id' => $implementationExecution
                        ->project_context_snapshot_id,
                    'capability' => ExecutionCapability::QualityAssuranceReview->value,
                    'logical_role' => 'qa_engineer',
                    'requested_reasoning_level' => ReasoningLevel::High,
                    'retry_limit' => $implementationExecution->retry_limit,
                    'timeout_seconds' => $implementationExecution->timeout_seconds,
                    'retry_base_delay_seconds' => $implementationExecution
                        ->retry_base_delay_seconds,
                    'retry_max_delay_seconds' => $implementationExecution
                        ->retry_max_delay_seconds,
                    'retry_jitter_percent' => $implementationExecution
                        ->retry_jitter_percent,
                    'correlation_id' => $implementationExecution
                        ->correlation_id,
                    'idempotency_key' => sprintf(
                        'quality-assurance:%d:%s',
                        $ticket->id,
                        $implementationExecution->id,
                    ),
                ]);

                $independence = $this->independence->evaluate(
                    implementationExecution: $implementationExecution,
                    reviewExecution: $reviewExecution,
                );

                if (! $independence->allowed) {
                    throw new LogicException(sprintf(
                        'Layer 3 independence policy rejected the execution: %s',
                        $independence->reason->value,
                    ));
                }

                return QaAssessment::query()->create([
                    'project_id' => $project->id,
                    'roadmap_task_id' => $ticket->id,
                    'implementation_execution_id' => $implementationExecution->id,
                    'implementation_attempt_id' => $implementationAttempt->id,
                    'review_execution_id' => $reviewExecution->id,
                    'status' => QaAssessment::STATUS_QUEUED,
                    'simulation_scenario' => $scenario,
                    'simulation_seed' => $seed,
                ]);
            },
            attempts: 3,
        );
    }
}
