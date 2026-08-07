<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Codex\CodexCleanupResource;
use App\Domain\Codex\CodexCleanupStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Records one idempotent cleanup resource for a provider process session.
 *
 * @property string $id
 * @property string $provider_session_id
 * @property CodexCleanupResource $resource
 * @property CodexCleanupStatus $status
 * @property int $attempt_count
 * @property string|null $last_error_code
 * @property string|null $last_error_message
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read ProviderSession $providerSession
 */
#[DateFormat('Y-m-d H:i:s.u')]
final class ProviderSessionCleanup extends Model
{
    use HasUlids;

    /**
     * Protect provider-session cleanup history from deletion or reassignment.
     */
    protected static function booted(): void
    {
        self::updating(static function (self $cleanup): void {
            foreach (
                [
                    'provider_session_id',
                    'resource',
                ] as $attribute
            ) {
                if ($cleanup->isDirty($attribute)) {
                    throw new LogicException(
                        'Provider cleanup identity is immutable.',
                    );
                }
            }
        });

        self::deleting(static function (): void {
            throw new LogicException(
                'Provider cleanup history cannot be deleted.',
            );
        });
    }

    /**
     * Return the provider session owning this cleanup record.
     *
     * @return BelongsTo<ProviderSession, $this>
     */
    public function providerSession(): BelongsTo
    {
        return $this->belongsTo(
            ProviderSession::class,
        );
    }

    /**
     * Cast cleanup state and timestamps.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'resource' => CodexCleanupResource::class,
            'status' => CodexCleanupStatus::class,
            'attempt_count' => 'integer',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
