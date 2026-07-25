<?php

declare(strict_types=1);

namespace App\Application\Audit\Data;

use App\Domain\Audit\AuditActorType;
use InvalidArgumentException;

/**
 * Carries the actor and distributed trace identity of one application command.
 */
final readonly class AuditContext
{
    /**
     * Create an immutable audit context.
     */
    public function __construct(
        public AuditActorType $actorType,
        public string $actorId,
        public ?string $correlationId = null,
        public ?string $causationId = null,
        public ?string $executionId = null,
    ) {}

    /**
     * Create context for an authenticated human command.
     */
    public static function user(
        int $userId,
        ?string $correlationId = null,
        ?string $causationId = null,
        ?string $executionId = null,
    ): self {
        if ($userId < 1) {
            throw new InvalidArgumentException(
                'The audit user identifier must be positive.',
            );
        }

        return new self(
            actorType: AuditActorType::User,
            actorId: (string) $userId,
            correlationId: $correlationId,
            causationId: $causationId,
            executionId: $executionId,
        );
    }

    /**
     * Create context for an application-owned worker or system command.
     */
    public static function system(
        string $actorId,
        ?string $correlationId = null,
        ?string $causationId = null,
        ?string $executionId = null,
    ): self {
        return new self(
            actorType: AuditActorType::System,
            actorId: $actorId,
            correlationId: $correlationId,
            causationId: $causationId,
            executionId: $executionId,
        );
    }

    /**
     * Preserve the trace while making a new event the direct cause.
     */
    public function causedBy(string $eventId): self
    {
        return new self(
            actorType: $this->actorType,
            actorId: $this->actorId,
            correlationId: $this->correlationId,
            causationId: $eventId,
            executionId: $this->executionId,
        );
    }
}
