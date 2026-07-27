<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Approvals\ApprovalStatus;
use App\Domain\Approvals\ApprovalType;
use Carbon\CarbonImmutable;
use Database\Factories\ApprovalFactory;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Stores one immutable approval request and its optional terminal decision.
 *
 * @property string $id
 * @property int $project_id
 * @property int|null $workflow_instance_id
 * @property string|null $execution_id
 * @property ApprovalType $type
 * @property ApprovalStatus $status
 * @property int|null $requested_by_user_id
 * @property int|null $decided_by_user_id
 * @property string $request_idempotency_key
 * @property string $request_fingerprint
 * @property string|null $decision_idempotency_key
 * @property string|null $decision_fingerprint
 * @property array<string, mixed> $request_payload
 * @property string|null $decision_reason
 * @property CarbonImmutable $requested_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $decided_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Project $project
 * @property-read WorkflowInstance|null $workflowInstance
 * @property-read Execution|null $execution
 * @property-read User|null $requestedBy
 * @property-read User|null $decidedBy
 */
#[DateFormat('Y-m-d H:i:s.u')]
#[Fillable([
    'project_id',
    'workflow_instance_id',
    'execution_id',
    'type',
    'requested_by_user_id',
    'request_idempotency_key',
    'request_fingerprint',
    'request_payload',
    'requested_at',
    'expires_at',
])]
final class Approval extends Model
{
    /** @use HasFactory<ApprovalFactory> */
    use HasFactory;

    use HasUlids;

    /**
     * Define the authoritative initial approval status.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * Protect request identity and prevent approval-history deletion.
     */
    protected static function booted(): void
    {
        self::updating(static function (self $approval): void {
            foreach (
                [
                    'project_id',
                    'workflow_instance_id',
                    'execution_id',
                    'type',
                    'requested_by_user_id',
                    'request_idempotency_key',
                    'request_fingerprint',
                    'request_payload',
                    'requested_at',
                    'expires_at',
                ] as $attribute
            ) {
                if ($approval->isDirty($attribute)) {
                    throw new LogicException(
                        'Approval request identity and context are immutable.',
                    );
                }
            }
        });

        self::deleting(static function (): void {
            throw new LogicException(
                'Approval history cannot be deleted.',
            );
        });
    }

    /**
     * Return the project that owns this approval.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Return the workflow instance associated with this approval.
     *
     * @return BelongsTo<WorkflowInstance, $this>
     */
    public function workflowInstance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class);
    }

    /**
     * Return the execution associated with this approval.
     *
     * @return BelongsTo<Execution, $this>
     */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(Execution::class);
    }

    /**
     * Return the user who requested this approval, when user initiated.
     *
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'requested_by_user_id',
        );
    }

    /**
     * Return the user who made the terminal decision.
     *
     * @return BelongsTo<User, $this>
     */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'decided_by_user_id',
        );
    }

    /**
     * Scope a query to one explicit project boundary.
     *
     * @param  Builder<Approval>  $query
     * @return Builder<Approval>
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
     * Scope a query to pending approvals that have reached expiry.
     *
     * @param  Builder<Approval>  $query
     * @return Builder<Approval>
     */
    public function scopeDue(
        Builder $query,
        CarbonImmutable $at,
    ): Builder {
        return $query
            ->where('status', ApprovalStatus::Pending->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $at);
    }

    /**
     * Determine whether this approval is pending and already due.
     */
    public function isDue(CarbonImmutable $at): bool
    {
        return $this->status === ApprovalStatus::Pending
            && $this->expires_at !== null
            && $this->expires_at->lessThanOrEqualTo($at);
    }

    /**
     * Cast database values to stable domain and date types.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ApprovalType::class,
            'status' => ApprovalStatus::class,
            'request_payload' => 'array',
            'requested_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
        ];
    }
}
