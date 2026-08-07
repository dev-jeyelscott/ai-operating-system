<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Audit\AuditActorType;
use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\AuditSubjectType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Immutable persistence model for one application audit event.
 *
 * @property int $sequence
 * @property string $event_id
 * @property int $organization_id
 * @property int|null $project_id
 * @property AuditActorType $actor_type
 * @property string $actor_id
 * @property AuditEventType $event_type
 * @property AuditSubjectType $subject_type
 * @property string $subject_id
 * @property string|null $correlation_id
 * @property array<string, mixed> $metadata
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $created_at
 * @property string|null $causation_id
 * @property string|null $execution_id
 * @property int $schema_version
 * @property string|null $deduplication_key
 */
#[Fillable([
    'event_id',
    'organization_id',
    'project_id',
    'actor_type',
    'actor_id',
    'event_type',
    'subject_type',
    'subject_id',
    'correlation_id',
    'metadata',
    'occurred_at',
    'causation_id',
    'execution_id',
    'schema_version',
    'deduplication_key',
])]
final class AuditEvent extends Model
{
    protected $table = 'audit_events';

    protected $primaryKey = 'sequence';

    protected $keyType = 'int';

    public $incrementing = true;

    public $timestamps = false;

    /**
     * Cast persisted values to domain-safe types.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'actor_type' => AuditActorType::class,
            'event_type' => AuditEventType::class,
            'subject_type' => AuditSubjectType::class,
            'schema_version' => 'integer',
            'metadata' => 'array',
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
