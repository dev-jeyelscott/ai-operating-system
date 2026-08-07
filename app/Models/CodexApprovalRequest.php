<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Codex\CodexApprovalCategory;
use App\Domain\Codex\CodexApprovalDeliveryStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Correlates one immutable Codex request with the generic approval engine.
 *
 * The generic Approval remains the source of truth for the human decision.
 * This record owns provider lineage and replay-safe provider delivery state.
 *
 * @property string $id
 * @property string $approval_id
 * @property string $provider_session_id
 * @property string $provider_event_id
 * @property int $organization_id
 * @property int $project_id
 * @property string $execution_id
 * @property int $execution_attempt_id
 * @property string $provider_request_key
 * @property string $provider_thread_id
 * @property string $provider_turn_id
 * @property string $provider_item_id
 * @property int $provider_sequence
 * @property string $provider_method
 * @property CodexApprovalCategory $category
 * @property string $risk_level
 * @property array<string, mixed> $request_context
 * @property string $request_fingerprint_sha256
 * @property string $workspace_fingerprint_sha256
 * @property string $policy_fingerprint_sha256
 * @property array<string, mixed> $policy_snapshot
 * @property string $safe_summary
 * @property array<string, mixed>|null $provider_result
 * @property string|null $provider_result_fingerprint_sha256
 * @property CodexApprovalDeliveryStatus $delivery_status
 * @property int $delivery_attempts
 * @property string|null $last_delivery_error_code
 * @property CarbonImmutable|null $last_delivery_attempt_at
 * @property CarbonImmutable|null $delivered_at
 * @property CarbonImmutable|null $provider_resolved_at
 * @property-read Approval $approval
 * @property-read ProviderSession $providerSession
 * @property-read ProviderEvent $providerEvent
 * @property-read Project $project
 * @property-read Execution $execution
 * @property-read ExecutionAttempt $executionAttempt
 */
#[DateFormat('Y-m-d H:i:s.u')]
#[Fillable([
    'approval_id',
    'provider_session_id',
    'provider_event_id',
    'organization_id',
    'project_id',
    'execution_id',
    'execution_attempt_id',
    'provider_request_key',
    'provider_thread_id',
    'provider_turn_id',
    'provider_item_id',
    'provider_sequence',
    'provider_method',
    'category',
    'risk_level',
    'request_context',
    'request_fingerprint_sha256',
    'workspace_fingerprint_sha256',
    'policy_fingerprint_sha256',
    'policy_snapshot',
    'safe_summary',
])]
final class CodexApprovalRequest extends Model
{
    use HasUlids;

    /**
     * Never expose encrypted protocol context through accidental serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'request_context',
        'provider_result',
    ];

    /**
     * Protect provider request identity and decision payload from mutation.
     */
    protected static function booted(): void
    {
        self::updating(
            static function (self $request): void {
                foreach (
                    [
                        'approval_id',
                        'provider_session_id',
                        'provider_event_id',
                        'organization_id',
                        'project_id',
                        'execution_id',
                        'execution_attempt_id',
                        'provider_request_key',
                        'provider_thread_id',
                        'provider_turn_id',
                        'provider_item_id',
                        'provider_sequence',
                        'provider_method',
                        'category',
                        'risk_level',
                        'request_context',
                        'request_fingerprint_sha256',
                        'workspace_fingerprint_sha256',
                        'policy_fingerprint_sha256',
                        'policy_snapshot',
                        'safe_summary',
                    ] as $attribute
                ) {
                    if ($request->isDirty($attribute)) {
                        throw new LogicException(
                            'Codex approval request identity and decision context are immutable.',
                        );
                    }
                }

                /*
                 * Once prepared, the provider response is immutable. Recovery
                 * must replay the exact same result rather than create another.
                 */
                if (
                    $request->getOriginal('provider_result') !== null
                    && $request->isDirty('provider_result')
                ) {
                    throw new LogicException(
                        'Prepared Codex approval provider results are immutable.',
                    );
                }

                if (
                    $request->getOriginal(
                        'provider_result_fingerprint_sha256',
                    ) !== null
                    && $request->isDirty(
                        'provider_result_fingerprint_sha256',
                    )
                ) {
                    throw new LogicException(
                        'Prepared Codex approval result fingerprints are immutable.',
                    );
                }
            },
        );

        self::deleting(static function (): void {
            throw new LogicException(
                'Codex approval history cannot be deleted.',
            );
        });
    }

    /**
     * Return the generic application approval.
     *
     * @return BelongsTo<Approval, $this>
     */
    public function approval(): BelongsTo
    {
        return $this->belongsTo(Approval::class);
    }

    /**
     * Return the provider process session that issued the request.
     *
     * @return BelongsTo<ProviderSession, $this>
     */
    public function providerSession(): BelongsTo
    {
        return $this->belongsTo(ProviderSession::class);
    }

    /**
     * Return the immutable provider event that created this request.
     *
     * @return BelongsTo<ProviderEvent, $this>
     */
    public function providerEvent(): BelongsTo
    {
        return $this->belongsTo(ProviderEvent::class);
    }

    /**
     * Return the owning project.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Return the logical execution that owns the provider request.
     *
     * @return BelongsTo<Execution, $this>
     */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(Execution::class);
    }

    /**
     * Return the exact attempt that owns the provider process.
     *
     * @return BelongsTo<ExecutionAttempt, $this>
     */
    public function executionAttempt(): BelongsTo
    {
        return $this->belongsTo(ExecutionAttempt::class);
    }

    /**
     * Scope requests to one explicit project.
     *
     * @param  Builder<CodexApprovalRequest>  $query
     * @return Builder<CodexApprovalRequest>
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
     * Cast encrypted context and provider delivery state.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'organization_id' => 'integer',
            'project_id' => 'integer',
            'execution_attempt_id' => 'integer',
            'provider_sequence' => 'integer',
            'category' => CodexApprovalCategory::class,
            'request_context' => 'encrypted:array',
            'policy_snapshot' => 'array',
            'provider_result' => 'encrypted:array',
            'delivery_status' => CodexApprovalDeliveryStatus::class,
            'delivery_attempts' => 'integer',
            'last_delivery_attempt_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'provider_resolved_at' => 'immutable_datetime',
        ];
    }
}
