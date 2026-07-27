<?php

declare(strict_types=1);

namespace App\Application\Workflows\Data;

use App\Domain\Audit\AuditActorType;
use InvalidArgumentException;

/**
 * Carries the immutable actor, trace, and guard input for one transition.
 */
final readonly class WorkflowTransitionContext
{
    private const MAX_ACTOR_IDENTIFIER_LENGTH = 191;

    public string $actorId;

    /** @var array<string, mixed> */
    public array $guardContext;

    /**
     * Validate and store one transition context.
     *
     * @param  array<array-key, mixed>  $guardContext
     */
    public function __construct(
        public AuditActorType $actorType,
        string $actorId,
        public ?string $correlationId = null,
        public ?string $causationId = null,
        public ?string $executionId = null,
        array $guardContext = [],
    ) {
        $normalizedActorId = trim($actorId);

        if ($normalizedActorId === '') {
            throw new InvalidArgumentException(
                'The workflow transition actor identifier is required.',
            );
        }

        if (
            mb_strlen($normalizedActorId)
            > self::MAX_ACTOR_IDENTIFIER_LENGTH
        ) {
            throw new InvalidArgumentException(
                'The workflow transition actor identifier may not exceed 191 characters.',
            );
        }

        foreach (array_keys($guardContext) as $key) {
            if (! is_string($key)) {
                throw new InvalidArgumentException(
                    'Workflow transition guard context keys must be strings.',
                );
            }
        }

        $this->actorId = $normalizedActorId;
        $this->guardContext = $guardContext;
    }

    /**
     * Create context for an authenticated user transition.
     *
     * @param  array<string, mixed>  $guardContext
     */
    public static function user(
        int $userId,
        ?string $correlationId = null,
        ?string $causationId = null,
        ?string $executionId = null,
        array $guardContext = [],
    ): self {
        if ($userId < 1) {
            throw new InvalidArgumentException(
                'The workflow transition user identifier must be positive.',
            );
        }

        return new self(
            actorType: AuditActorType::User,
            actorId: (string) $userId,
            correlationId: $correlationId,
            causationId: $causationId,
            executionId: $executionId,
            guardContext: $guardContext,
        );
    }

    /**
     * Create context for an application-owned workflow worker.
     *
     * @param  array<string, mixed>  $guardContext
     */
    public static function system(
        string $actorId,
        ?string $correlationId = null,
        ?string $causationId = null,
        ?string $executionId = null,
        array $guardContext = [],
    ): self {
        return new self(
            actorType: AuditActorType::System,
            actorId: $actorId,
            correlationId: $correlationId,
            causationId: $causationId,
            executionId: $executionId,
            guardContext: $guardContext,
        );
    }
}
