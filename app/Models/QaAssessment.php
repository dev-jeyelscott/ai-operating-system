<?php

declare(strict_types=1);

namespace App\Models;

use App\Application\QualityAssurance\Data\QaAssessmentResult;
use App\Domain\QualityAssurance\QaDecision;
use App\Domain\QualityAssurance\QaImpactLevel;
use App\Domain\QualityAssurance\QaReviewStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Stores one durable Layer 3 orchestration record and its final assessment.
 *
 * Provider retries remain attempts under the same review execution. The
 * implementation and review lineage never changes after creation.
 *
 * @property string $id
 * @property int $project_id
 * @property int $roadmap_task_id
 * @property string $implementation_execution_id
 * @property int $implementation_attempt_id
 * @property string $review_execution_id
 * @property int|null $review_attempt_id
 * @property string $status
 * @property string $simulation_scenario
 * @property int $simulation_seed
 * @property int|null $result_schema_version
 * @property QaDecision|null $decision
 * @property string|null $confidence
 * @property string|null $target_branch
 * @property bool|null $ticket_scope_satisfied
 * @property bool|null $acceptance_criteria_verified
 * @property QaReviewStatus|null $ci_status
 * @property QaReviewStatus|null $test_status
 * @property QaReviewStatus|null $architecture_status
 * @property QaReviewStatus|null $security_status
 * @property QaImpactLevel|null $database_impact
 * @property QaImpactLevel|null $performance_impact
 * @property QaImpactLevel|null $regression_risk
 * @property QaImpactLevel|null $rollback_complexity
 * @property list<array<string, mixed>>|null $unresolved_findings
 * @property list<array<string, mixed>>|null $merge_risks
 * @property string|null $recommendation
 * @property list<string>|null $evidence_ids
 * @property string|null $canonical_assessment_fingerprint
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[DateFormat('Y-m-d H:i:s.u')]
final class QaAssessment extends Model
{
    use HasUlids;

    public const string STATUS_QUEUED = 'queued';

    public const string STATUS_RUNNING = 'running';

    public const string STATUS_RETRY_SCHEDULED = 'retry_scheduled';

    public const string STATUS_COMPLETED = 'completed';

    public const string STATUS_FAILED = 'failed';

    public const string STATUS_CANCELLED = 'cancelled';

    /**
     * Allow the model's explicit lifecycle methods to persist controlled updates.
     */
    private bool $authoritativeUpdate = false;

    /**
     * Permit creation through the orchestration service.
     *
     * Updates remain protected by the model events below.
     *
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * Define the initial assessment state.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => self::STATUS_QUEUED,
        'simulation_scenario' => 'happy_path',
        'simulation_seed' => 106,
    ];

    /**
     * Reject arbitrary updates and deletion of QA provenance.
     */
    protected static function booted(): void
    {
        self::updating(static function (self $assessment): void {
            if (! $assessment->authoritativeUpdate) {
                throw new LogicException(
                    'QA assessments must be changed through their lifecycle methods.',
                );
            }
        });

        self::deleting(static function (): void {
            throw new LogicException(
                'QA assessment history cannot be deleted.',
            );
        });
    }

    /**
     * Mark a queued or retry-released assessment as actively running.
     */
    public function markRunning(int $reviewAttemptId): void
    {
        if ($reviewAttemptId < 1) {
            throw new LogicException(
                'QA review attempt ID must be positive.',
            );
        }

        $this->transition(
            allowedStatuses: [
                self::STATUS_QUEUED,
                self::STATUS_RETRY_SCHEDULED,
            ],
            attributes: [
                'status' => self::STATUS_RUNNING,
                'review_attempt_id' => $reviewAttemptId,
            ],
        );
    }

    /**
     * Record that execution resilience scheduled another provider attempt.
     */
    public function markRetryScheduled(): void
    {
        $this->transition(
            allowedStatuses: [self::STATUS_RUNNING],
            attributes: [
                'status' => self::STATUS_RETRY_SCHEDULED,
            ],
        );
    }

    /**
     * Persist the canonical successful Layer 3 result.
     */
    public function markCompleted(QaAssessmentResult $result): void
    {
        $this->transition(
            allowedStatuses: [self::STATUS_RUNNING],
            attributes: [
                'status' => self::STATUS_COMPLETED,
                'result_schema_version' => $result->schemaVersion,
                'decision' => $result->decision,
                'confidence' => $result->confidence,
                'target_branch' => $result->targetBranch,
                'ticket_scope_satisfied' => $result->ticketScopeSatisfied,
                'acceptance_criteria_verified' => $result->acceptanceCriteriaVerified,
                'ci_status' => $result->ciStatus,
                'test_status' => $result->testStatus,
                'architecture_status' => $result->architectureStatus,
                'security_status' => $result->securityStatus,
                'database_impact' => $result->databaseImpact,
                'performance_impact' => $result->performanceImpact,
                'regression_risk' => $result->regressionRisk,
                'rollback_complexity' => $result->rollbackComplexity,
                'unresolved_findings' => array_map(
                    static fn ($finding): array => $finding->toArray(),
                    $result->unresolvedFindings,
                ),
                'merge_risks' => array_map(
                    static fn ($risk): array => $risk->toArray(),
                    $result->mergeRisks,
                ),
                'recommendation' => $result->recommendation,
                'evidence_ids' => $result->evidenceIds,
                'canonical_assessment_fingerprint' => $result->canonicalAssessmentFingerprint,
            ],
        );
    }

    /**
     * Mark terminal execution failure without deleting earlier attempts.
     */
    public function markFailed(): void
    {
        $this->transition(
            allowedStatuses: [
                self::STATUS_QUEUED,
                self::STATUS_RUNNING,
                self::STATUS_RETRY_SCHEDULED,
            ],
            attributes: [
                'status' => self::STATUS_FAILED,
            ],
        );
    }

    /**
     * Mark cancellation after the execution manager confirms it.
     */
    public function markCancelled(): void
    {
        $this->transition(
            allowedStatuses: [
                self::STATUS_QUEUED,
                self::STATUS_RUNNING,
                self::STATUS_RETRY_SCHEDULED,
            ],
            attributes: [
                'status' => self::STATUS_CANCELLED,
            ],
        );
    }

    /**
     * Return the project that owns this assessment.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Return the ticket under review.
     *
     * @return BelongsTo<RoadmapTask, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(
            RoadmapTask::class,
            'roadmap_task_id',
        );
    }

    /**
     * Return the completed Layer 2 implementation execution.
     *
     * @return BelongsTo<Execution, $this>
     */
    public function implementationExecution(): BelongsTo
    {
        return $this->belongsTo(
            Execution::class,
            'implementation_execution_id',
        );
    }

    /**
     * Return the Layer 2 provider attempt whose artifacts are reviewed.
     *
     * @return BelongsTo<ExecutionAttempt, $this>
     */
    public function implementationAttempt(): BelongsTo
    {
        return $this->belongsTo(
            ExecutionAttempt::class,
            'implementation_attempt_id',
        );
    }

    /**
     * Return the independent Layer 3 review execution.
     *
     * @return BelongsTo<Execution, $this>
     */
    public function reviewExecution(): BelongsTo
    {
        return $this->belongsTo(
            Execution::class,
            'review_execution_id',
        );
    }

    /**
     * Return the current or final Layer 3 provider attempt.
     *
     * @return BelongsTo<ExecutionAttempt, $this>
     */
    public function reviewAttempt(): BelongsTo
    {
        return $this->belongsTo(
            ExecutionAttempt::class,
            'review_attempt_id',
        );
    }

    /**
     * Scope assessments to an explicit project boundary.
     *
     * @param  Builder<QaAssessment>  $query
     * @return Builder<QaAssessment>
     */
    public function scopeForProject(
        Builder $query,
        int $projectId,
    ): Builder {
        return $query->where(
            $query->getModel()->qualifyColumn('project_id'),
            $projectId,
        );
    }

    /**
     * Persist one authorized lifecycle transition.
     *
     * @param  list<string>  $allowedStatuses
     * @param  array<string, mixed>  $attributes
     */
    private function transition(
        array $allowedStatuses,
        array $attributes,
    ): void {
        if (! in_array($this->status, $allowedStatuses, true)) {
            throw new LogicException(sprintf(
                'QA assessment cannot transition from [%s].',
                $this->status,
            ));
        }

        $this->authoritativeUpdate = true;

        try {
            $this->forceFill($attributes)->save();
        } finally {
            $this->authoritativeUpdate = false;
        }
    }

    /**
     * Cast persisted values to canonical domain and PHP types.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'project_id' => 'integer',
            'roadmap_task_id' => 'integer',
            'implementation_attempt_id' => 'integer',
            'review_attempt_id' => 'integer',
            'simulation_seed' => 'integer',
            'result_schema_version' => 'integer',
            'decision' => QaDecision::class,
            'confidence' => 'decimal:4',
            'ticket_scope_satisfied' => 'boolean',
            'acceptance_criteria_verified' => 'boolean',
            'ci_status' => QaReviewStatus::class,
            'test_status' => QaReviewStatus::class,
            'architecture_status' => QaReviewStatus::class,
            'security_status' => QaReviewStatus::class,
            'database_impact' => QaImpactLevel::class,
            'performance_impact' => QaImpactLevel::class,
            'regression_risk' => QaImpactLevel::class,
            'rollback_complexity' => QaImpactLevel::class,
            'unresolved_findings' => 'array',
            'merge_risks' => 'array',
            'evidence_ids' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
