<?php

declare(strict_types=1);

namespace App\Domain\Events;

use InvalidArgumentException;

/**
 * Stores the immutable historical identity of the event actor.
 */
final readonly class DomainEventActor
{
    private const MAX_IDENTIFIER_LENGTH = 191;

    public DomainEventActorType $type;

    public string $id;

    /**
     * Validate and store the actor identity.
     */
    public function __construct(
        DomainEventActorType $type,
        string $id,
    ) {
        $normalizedId = trim($id);

        if ($normalizedId === '') {
            throw new InvalidArgumentException(
                'The domain event actor identifier is required.',
            );
        }

        if (mb_strlen($normalizedId) > self::MAX_IDENTIFIER_LENGTH) {
            throw new InvalidArgumentException(
                'The domain event actor identifier may not exceed 191 characters.',
            );
        }

        $this->type = $type;
        $this->id = $normalizedId;
    }

    /**
     * Create an actor for an authenticated user.
     */
    public static function user(int $userId): self
    {
        if ($userId < 1) {
            throw new InvalidArgumentException(
                'The domain event user identifier must be positive.',
            );
        }

        return new self(
            type: DomainEventActorType::User,
            id: (string) $userId,
        );
    }

    /**
     * Create an actor for an application-owned system worker.
     */
    public static function system(string $actorId): self
    {
        return new self(
            type: DomainEventActorType::System,
            id: $actorId,
        );
    }

    /**
     * Create an actor for a logical agent.
     */
    public static function agent(string $actorId): self
    {
        return new self(
            type: DomainEventActorType::Agent,
            id: $actorId,
        );
    }

    /**
     * Create an actor for an execution provider.
     */
    public static function provider(string $actorId): self
    {
        return new self(
            type: DomainEventActorType::Provider,
            id: $actorId,
        );
    }

    /**
     * Serialize the actor into the canonical envelope shape.
     *
     * @return array{type: string, id: string}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'id' => $this->id,
        ];
    }
}
