<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Codex\ProviderEventType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Stores one immutable, normalized and redacted provider event.
 *
 * Raw App Server protocol messages must never be stored in this table.
 *
 * @property string $id
 * @property string $provider_session_id
 * @property int $organization_id
 * @property int $project_id
 * @property string $execution_id
 * @property int $execution_attempt_id
 * @property int $sequence
 * @property ProviderEventType $event_type
 * @property string $provider_method
 * @property string|null $provider_request_id
 * @property string|null $provider_thread_id
 * @property string|null $provider_turn_id
 * @property string|null $provider_item_id
 * @property string|null $provider_cursor
 * @property string $provider_identity_sha256
 * @property string $payload_fingerprint_sha256
 * @property array<string, mixed> $payload
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $created_at
 */
#[DateFormat('Y-m-d H:i:s.u')]
#[Fillable([
    'provider_session_id',
    'organization_id',
    'project_id',
    'execution_id',
    'execution_attempt_id',
    'sequence',
    'event_type',
    'provider_method',
    'provider_request_id',
    'provider_thread_id',
    'provider_turn_id',
    'provider_item_id',
    'provider_cursor',
    'provider_identity_sha256',
    'payload_fingerprint_sha256',
    'payload',
    'occurred_at',
])]
final class ProviderEvent extends Model
{
    use HasUlids;

    public $timestamps = false;

    /**
     * Prevent mutation or deletion of normalized provider history.
     */
    protected static function booted(): void
    {
        self::updating(static function (): void {
            throw new LogicException(
                'Provider events are immutable.',
            );
        });

        self::deleting(static function (): void {
            throw new LogicException(
                'Provider events cannot be deleted.',
            );
        });
    }

    /**
     * Return the provider session that emitted the event.
     */
    public function providerSession(): BelongsTo
    {
        return $this->belongsTo(
            ProviderSession::class,
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
     * Return the execution attempt.
     */
    public function executionAttempt(): BelongsTo
    {
        return $this->belongsTo(
            ExecutionAttempt::class,
        );
    }

    /**
     * Cast normalized payload and lifecycle values safely.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'event_type' => ProviderEventType::class,
            'payload' => 'array',
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
