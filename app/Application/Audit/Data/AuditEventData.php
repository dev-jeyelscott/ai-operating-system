<?php

declare(strict_types=1);

namespace App\Application\Audit\Data;

use App\Domain\Audit\AuditActorType;
use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\AuditSubjectType;
use Carbon\CarbonImmutable;

/**
 * Immutable application payload representing one audit event to append.
 */
final readonly class AuditEventData
{
    /**
     * Create an immutable audit-event payload.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $eventId,
        public int $organizationId,
        public ?int $projectId,
        public AuditActorType $actorType,
        public string $actorId,
        public AuditEventType $eventType,
        public AuditSubjectType $subjectType,
        public string $subjectId,
        public ?string $correlationId,
        public array $metadata,
        public CarbonImmutable $occurredAt,
    ) {}
}
