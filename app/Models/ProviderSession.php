<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Codex\ProviderSessionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Stores durable lineage and liveness for one provider process session.
 *
 * Provider session state is operational evidence. It does not authorize or
 * perform workflow transitions.
 *
 * @property string $id
 * @property int $organization_id
 * @property int $project_id
 * @property string $execution_id
 * @property int $execution_attempt_id
 * @property string $provider
 * @property string|null $binary_version
 * @property string $protocol_version
 * @property string $protocol_schema_fingerprint
 * @property string|null $model_identifier
 * @property string $sandbox_profile
 * @property string $network_policy
 * @property string|null $provider_thread_id
 * @property string|null $provider_turn_id
 * @property int|null $runtime_process_id
 * @property ProviderSessionStatus $status
 * @property int $last_provider_sequence
 * @property string|null $last_provider_cursor
 * @property CarbonImmutable|null $process_started_at
 * @property CarbonImmutable|null $initialized_at
 * @property CarbonImmutable|null $heartbeat_at
 * @property CarbonImmutable|null $terminal_at
 * @property string|null $terminal_status
 * @property string|null $terminal_code
 * @property CarbonImmutable|null $cancellation_requested_at
 * @property string|null $cleanup_status
 * @property CarbonImmutable|null $transcript_truncated_at
 */
#[DateFormat('Y-m-d H:i:s.u')]
#[Fillable([
    'id',
    'organization_id',
    'project_id',
    'execution_id',
    'execution_attempt_id',
    'provider',
    'binary_version',
    'protocol_version',
    'protocol_schema_fingerprint',
    'model_identifier',
    'sandbox_profile',
    'network_policy',
    'provider_thread_id',
    'provider_turn_id',
    'runtime_process_id',
    'status',
    'last_provider_sequence',
    'last_provider_cursor',
    'process_started_at',
    'initialized_at',
    'heartbeat_at',
    'terminal_at',
    'terminal_status',
    'terminal_code',
    'cancellation_requested_at',
    'cleanup_status',
    'transcript_truncated_at',
])]
final class ProviderSession extends Model
{
    use HasUlids;

    /**
     * Protect permanent session lineage while permitting liveness updates.
     */
    protected static function booted(): void
    {
        self::updating(static function (self $session): void {
            foreach (
                [
                    'id',
                    'organization_id',
                    'project_id',
                    'execution_id',
                    'execution_attempt_id',
                    'provider',
                    'binary_version',
                    'protocol_version',
                    'protocol_schema_fingerprint',
                    'model_identifier',
                    'sandbox_profile',
                    'network_policy',
                ] as $attribute
            ) {
                if ($session->isDirty($attribute)) {
                    throw new LogicException(
                        'Provider session lineage is immutable.',
                    );
                }
            }
        });

        self::deleting(static function (): void {
            throw new LogicException(
                'Provider sessions cannot be deleted.',
            );
        });
    }

    /**
     * Return the owning organization.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(
            Organization::class,
        );
    }

    /**
     * Return the owning project.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(
            Project::class,
        );
    }

    /**
     * Return the logical execution.
     */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(
            Execution::class,
        );
    }

    /**
     * Return the immutable provider attempt.
     */
    public function executionAttempt(): BelongsTo
    {
        return $this->belongsTo(
            ExecutionAttempt::class,
        );
    }

    /**
     * Return normalized events in deterministic provider sequence.
     */
    public function events(): HasMany
    {
        return $this
            ->hasMany(ProviderEvent::class)
            ->orderBy('sequence');
    }

    /**
     * Cast persisted lifecycle values to stable types.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'runtime_process_id' => 'integer',
            'status' => ProviderSessionStatus::class,
            'last_provider_sequence' => 'integer',
            'process_started_at' => 'immutable_datetime',
            'initialized_at' => 'immutable_datetime',
            'heartbeat_at' => 'immutable_datetime',
            'terminal_at' => 'immutable_datetime',
            'cancellation_requested_at' => 'immutable_datetime',
            'transcript_truncated_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
